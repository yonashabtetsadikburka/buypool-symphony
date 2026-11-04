<?php

namespace App\Supplier\Service;

use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use App\Identity\Enum\UserRole;
use App\Identity\Repository\UserRepository;
use App\Identity\Service\PasswordPolicy;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierInvite;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Invito e attivazione dell'account fornitore (legacy: fornitori_invito_genera, fornitori_invito_verifica, fornitori_attiva).
 * L'admin genera un link valido 7 giorni; nel database resta solo l'hash SHA-256 del token. Il fornitore sceglie la password
 * e il suo account (ruolo fornitore) viene collegato alla scheda azienda. Il link non si può riusare.
 */
final class SupplierInviteService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(env: 'FRONTEND_URL')]
        private readonly string $frontendUrl,
    ) {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * Nuovo invito per un fornitore senza account. Gli inviti precedenti non usati vengono invalidati.
     *
     * @return array{token: string, link: string, scadenza_giorni: int, email: string}
     */
    public function generate(string $supplierId): array
    {
        $supplier = Uuid::isValid($supplierId) ? $this->em->find(Supplier::class, Uuid::fromString($supplierId)) : null;
        if (!$supplier instanceof Supplier) {
            throw new ApiError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
        }
        if (null !== $supplier->getUser()) {
            throw new ApiError('GIA_COLLEGATO', "Questo fornitore ha gia' un account attivo collegato", 409);
        }
        $email = trim((string) $supplier->getContactEmail());
        if ('' === $email) {
            throw new ApiError('EMAIL_MANCANTE', 'Inserire prima una email di contatto per questo fornitore');
        }

        return $this->transaction->run(function () use ($supplier, $email): array {
            foreach ($this->em->getRepository(SupplierInvite::class)->findBy(['supplier' => $supplier, 'usedAt' => null]) as $old) {
                $old->markUsed();
            }
            $token = bin2hex(random_bytes(32));
            $this->em->persist(new SupplierInvite($supplier, $email, self::hash($token), new \DateTimeImmutable('+'.self::VALID_DAYS.' days')));

            return [
                'token' => $token,
                'link' => rtrim($this->frontendUrl, '/').'/fornitore-attiva.html?token='.$token,
                'scadenza_giorni' => self::VALID_DAYS,
                'email' => $email,
            ];
        });
    }

    /**
     * Dati non sensibili per la pagina di attivazione. Token sconosciuto, scaduto o già usato: 410.
     *
     * @return array{nome_azienda: string, email: string}
     */
    public function verify(string $token): array
    {
        $invite = $this->em->getRepository(SupplierInvite::class)->findOneBy(['tokenHash' => self::hash($token)]);
        if (!$invite instanceof SupplierInvite || !$this->isUsable($invite)) {
            throw self::invalid();
        }

        return ['nome_azienda' => $invite->getSupplier()->getName(), 'email' => $invite->getEmail()];
    }

    /**
     * @param array<string, mixed> $data token, password, privacy
     */
    public function activate(array $data): User
    {
        $token = \is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $password = \is_string($data['password'] ?? null) ? $data['password'] : '';
        if ('' === $token) {
            throw new ApiError('TOKEN_MANCANTE', 'Token invito mancante');
        }
        if (!PasswordPolicy::isValid($password)) {
            throw new ApiError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri, una lettera e un numero');
        }
        if (empty($data['privacy'])) {
            throw new ApiError('PRIVACY_NON_ACCETTATA', "Devi accettare l'informativa privacy per attivare l'account");
        }

        return $this->transaction->run(function () use ($token, $password): User {
            $found = $this->em->getRepository(SupplierInvite::class)->findOneBy(['tokenHash' => self::hash($token)]);
            if (!$found instanceof SupplierInvite) {
                throw self::invalid();
            }
            // Blocco sulla riga dell'invito: due attivazioni insieme con lo stesso link, ne passa una sola.
            $invite = $this->em->find(SupplierInvite::class, $found->getId(), LockMode::PESSIMISTIC_WRITE) ?? $found;
            $this->em->refresh($invite);
            if (!$this->isUsable($invite)) {
                throw self::invalid();
            }
            // Email già registrata: non si duplica l'account, lo collega l'admin.
            if (null !== $this->users->findOneByEmail($invite->getEmail())) {
                throw new ApiError('EMAIL_ESISTENTE', 'Esiste gia\' un account con questa email: fai login e chiedi a BuyPool di collegarlo', 409);
            }
            $supplier = $invite->getSupplier();
            if (null !== $supplier->getUser()) {
                throw new ApiError('GIA_COLLEGATO', "Questo fornitore ha gia' un account collegato", 409);
            }

            // Il referente non si chiede qui: si parte dal nome dell'azienda.
            $user = (new User(mb_substr(trim($supplier->getName()), 0, 100), $invite->getEmail()))
                ->setRole(UserRole::Fornitore)
                ->setStatus(AccountStatus::Attivo)
                ->setPrivacyConsentedAt(new \DateTimeImmutable());
            $user->setHashedPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $supplier->setUser($user);

            foreach ($this->em->getRepository(SupplierInvite::class)->findBy(['supplier' => $supplier, 'usedAt' => null]) as $open) {
                $open->markUsed();
            }

            return $user;
        });
    }

    private function isUsable(SupplierInvite $invite): bool
    {
        return !$invite->isUsed() && !$invite->isExpired(new \DateTimeImmutable());
    }

    private static function invalid(): ApiError
    {
        return new ApiError('INVITO_NON_VALIDO', "Il link dell'invito non e' piu' valido", 410);
    }
}
