<?php

namespace App\Delivery\Service;

use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Enum\PickupTokenStatus;
use App\Delivery\Repository\DeliveryRepository;
use App\Delivery\Repository\PickupTokenRepository;
use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Participation\Entity\Participation;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ritiro in sede con codice QR (legacy: ritiro_qr, ritiro_conferma).
 *
 * Il database non conserva il codice in chiaro ma solo il suo hash (SHA-256): a ogni visualizzazione del QR il cliente
 * riceve un codice nuovo e quello precedente smette di valere. Il codice si può vedere solo dopo "Merce arrivata";
 * l'admin lo scansiona una sola volta.
 */
final class PickupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly PickupTokenRepository $tokens,
        private readonly DeliveryRepository $deliveries,
        private readonly NotificationService $notifications,
    ) {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * Crea il codice di ritiro di un'adesione (se manca) con un valore che nessuno conosce: il cliente ne riceve uno
     * nuovo alla prima visualizzazione. Non fa flush: lo fa chi chiama.
     */
    public function createFor(Participation $participation): PickupToken
    {
        $existing = $this->tokens->findOneBy(['participation' => $participation]);
        if ($existing instanceof PickupToken) {
            return $existing;
        }
        $token = new PickupToken($participation, self::hash(bin2hex(random_bytes(24))), $participation->getQuantity());
        $this->em->persist($token);

        return $token;
    }

    /**
     * QR del cliente. Un'adesione altrui, senza codice o con la merce non ancora arrivata dà 404.
     * Se il ritiro è già avvenuto si restituisce lo stato senza codice.
     *
     * @return array{token: ?string, quantita_assegnata: int, stato: string, scansionato: bool, data_scansione: ?string, stato_colletta: string}
     */
    public function show(User $user, string $participationId): array
    {
        $notFound = new ApiError('QR_NON_TROVATO', "Il codice di ritiro sara' disponibile quando la merce sara' arrivata", 404);
        if (!Uuid::isValid($participationId)) {
            throw $notFound;
        }

        return $this->transaction->run(function () use ($user, $participationId, $notFound): array {
            $participation = $this->em->find(Participation::class, Uuid::fromString($participationId));
            if (!$participation instanceof Participation || !$participation->getUser()->getId()->equals($user->getId())) {
                throw $notFound;
            }
            $token = $this->tokens->findOneBy(['participation' => $participation]);
            $campaign = $participation->getCampaign();
            if (!$token instanceof PickupToken || CampaignStatus::Consegnata !== $campaign->getStatus()) {
                throw $notFound;
            }

            $raw = null;
            if (PickupTokenStatus::Generato === $token->getStatus()) {
                $raw = bin2hex(random_bytes(24));
                $token->rotateTokenHash(self::hash($raw));
            }

            return [
                'token' => $raw,
                'quantita_assegnata' => $token->getAssignedQuantity(),
                'stato' => $token->getStatus()->value,
                'scansionato' => PickupTokenStatus::Scansionato === $token->getStatus(),
                'data_scansione' => $token->getScannedAt()?->format('Y-m-d H:i:s'),
                'stato_colletta' => $campaign->getStatus()->value,
            ];
        });
    }

    /**
     * Scansione dell'admin: segna il ritiro. Codice sconosciuto 404, già usato o annullato 409,
     * merce non ancora arrivata 409. Due scansioni insieme: la seconda trova il codice già usato.
     *
     * @return array{utente: string, quantita: int, prodotto: string, messaggio: string}
     */
    public function scan(string $token): array
    {
        $hash = self::hash($token);
        $found = $this->tokens->findOneByTokenHash($hash);
        if (!$found instanceof PickupToken) {
            throw new ApiError('TOKEN_NON_VALIDO', 'Codice di ritiro non valido', 404);
        }

        return $this->transaction->run(function () use ($found, $hash): array {
            $locked = $this->em->find(PickupToken::class, $found->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof PickupToken) {
                throw new ApiError('TOKEN_NON_VALIDO', 'Codice di ritiro non valido', 404);
            }
            $this->em->refresh($locked);
            // Il cliente può aver chiesto un QR nuovo mentre aspettavamo il blocco: il vecchio codice non vale più.
            if ($locked->getTokenHash() !== $hash) {
                throw new ApiError('TOKEN_NON_VALIDO', 'Codice di ritiro non valido', 404);
            }
            if (PickupTokenStatus::Scansionato === $locked->getStatus()) {
                throw new ApiError('GIA_RITIRATO', "Questo ordine e' gia' stato ritirato", 409);
            }
            if (PickupTokenStatus::Annullato === $locked->getStatus()) {
                throw new ApiError('QR_ANNULLATO', 'Questo codice di ritiro e\' stato annullato', 409);
            }
            $participation = $locked->getParticipation();
            $campaign = $participation->getCampaign();
            if (CampaignStatus::Consegnata !== $campaign->getStatus()) {
                throw new ApiError('RITIRO_NON_DISPONIBILE', 'La merce non e\' ancora arrivata: segna prima "Merce arrivata" sul gruppo', 409);
            }

            $now = new \DateTimeImmutable();
            $locked->markScanned();
            $delivery = $this->deliveries->findOneBy(['participation' => $participation]);
            $delivery?->setStatus(DeliveryStatus::Ritirata)->setDeliveredAt($now);

            $product = $campaign->getProduct()->getName();
            $customer = $participation->getUser();
            $this->notifications->notify(
                $customer,
                NotificationType::Ritirato,
                'Ritiro confermato',
                \sprintf('Hai ritirato "%s". Grazie per il tuo acquisto!', $product),
                ReferenceType::Prenotazione,
                $participation->getId()->toRfc4122(),
            );

            return [
                'utente' => trim($customer->getFirstName().' '.($customer->getLastName() ?? '')),
                'quantita' => $locked->getAssignedQuantity(),
                'prodotto' => $product,
                'messaggio' => 'Ritiro confermato.',
            ];
        });
    }
}
