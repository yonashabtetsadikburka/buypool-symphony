<?php

namespace App\Identity\Service;

use App\Identity\Entity\ExternalIdentity;
use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use App\Identity\Enum\ExternalProvider;
use App\Identity\Google\GoogleIdTokenVerifier;
use App\Identity\Repository\UserRepository;
use App\Shared\Http\ApiError;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accesso con Google (porta di oauth_risolvi() e oauth_completa() del legacy, con il token inviato dal client):
 *  - identità Google già collegata → accede;
 *  - nessun collegamento ma esiste un account con la stessa email → collega, solo se Google garantisce l'email;
 *  - nessun account → lo crea come cliente, a patto di avere il consenso privacy.
 */
final class GoogleLoginService
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<string, mixed> $data idToken, e per il primo accesso privacy, tipo, ragione_sociale, partita_iva
     */
    public function login(array $data): User
    {
        $idToken = \is_string($data['idToken'] ?? null) ? trim($data['idToken']) : '';
        if ('' === $idToken) {
            throw new ApiError('CAMPO_MANCANTE', 'Manca il campo: idToken');
        }

        $identity = $this->verifier->verify($idToken);

        $linked = $this->entityManager->getRepository(ExternalIdentity::class)
            ->findOneBy(['provider' => ExternalProvider::Google, 'subject' => $identity->subject]);
        if (null !== $linked) {
            return self::active($linked->getUser());
        }

        if ('' === $identity->email) {
            throw new ApiError('EMAIL_MANCANTE', "Google non ha fornito un'email per questo account", 403);
        }
        if (!$identity->emailVerified) {
            throw new ApiError('EMAIL_NON_VERIFICATA', "L'email del tuo account Google non è verificata", 403);
        }

        $user = $this->users->findOneByEmail($identity->email);
        if (null === $user) {
            if (empty($data['privacy'])) {
                throw new ApiError('PRIVACY_NON_ACCETTATA', "Devi accettare l'informativa privacy per creare l'account");
            }
            $user = (new User($identity->firstName, $identity->email))
                ->setLastName('' !== $identity->lastName ? $identity->lastName : null)
                ->setPrivacyConsentedAt(new \DateTimeImmutable());
            CustomerTypeApplier::apply($user, $data);
            $this->entityManager->persist($user);
        } elseif (null !== $this->entityManager->getRepository(ExternalIdentity::class)->findOneBy(['provider' => ExternalProvider::Google, 'user' => $user])) {
            // L'account è già legato a un'altra identità Google.
            throw new ApiError('NON_COLLEGABILE', 'Questo account è già collegato a un altro account Google', 409);
        }

        self::active($user);
        $this->entityManager->persist(new ExternalIdentity(ExternalProvider::Google, $identity->subject, $user));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ApiError('EMAIL_GIA_USATA', 'Questa email è già registrata. Accedi o reimposta la password.', 409);
        }

        return $user;
    }

    private static function active(User $user): User
    {
        if (AccountStatus::Sospeso === $user->getStatus()) {
            throw new ApiError('UTENTE_SOSPESO', "Account sospeso: non puoi accedere. Contatta l'assistenza.", 403);
        }

        return $user;
    }
}
