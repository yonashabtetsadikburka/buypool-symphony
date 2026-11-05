<?php

namespace App\Delivery\Service;

use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Participation\Enum\ParticipationStatus;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spedizione a domicilio segnata dall'admin (legacy: consegna_spedisci_esegui), US-20.
 * Solo dopo "Merce arrivata" sul gruppo (quindi con l'ordine al fornitore evaso) e solo per adesioni pagate; il tracking
 * è facoltativo. Il cliente riceve la notifica "Ordine spedito" una sola volta (la seconda richiesta dà 409).
 */
final class DeliveryShippingService
{
    public const MAX_TRACKING = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<string, mixed> $input tracking?
     *
     * @return array{spedita: bool, tracking: ?string}
     */
    public function ship(string $deliveryId, array $input): array
    {
        $tracking = $this->tracking($input['tracking'] ?? null);
        if (!Uuid::isValid($deliveryId)) {
            throw new ApiError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
        }

        return $this->transaction->run(function () use ($deliveryId, $tracking): array {
            $delivery = $this->em->find(Delivery::class, Uuid::fromString($deliveryId), LockMode::PESSIMISTIC_WRITE);
            if (!$delivery instanceof Delivery) {
                throw new ApiError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
            }
            $this->em->refresh($delivery);
            $participation = $delivery->getParticipation();
            $campaign = $participation->getCampaign();

            if (DeliveryMode::ConsegnaDomicilio !== $delivery->getMode()) {
                throw new ApiError('NON_SPEDIBILE', 'Solo le spedizioni a domicilio si possono segnare come spedite');
            }
            if (DeliveryStatus::Spedita === $delivery->getStatus()) {
                throw new ApiError('GIA_SPEDITA', 'Già segnata come spedita', 409);
            }
            if (DeliveryStatus::Consegnata === $delivery->getStatus()) {
                throw new ApiError('GIA_CONSEGNATA', 'Ordine già ricevuto dal cliente', 409);
            }
            $this->em->refresh($participation);
            if (ParticipationStatus::Pagata !== $participation->getStatus()) {
                throw new ApiError('NON_SPEDIBILE', "L'adesione non è pagata: non si può spedire", 409);
            }
            $this->em->refresh($campaign);
            if (CampaignStatus::Consegnata !== $campaign->getStatus()) {
                throw new ApiError('SPEDIZIONE_BLOCCATA', 'La merce non è ancora arrivata: segna prima "Merce arrivata" sul gruppo', 409);
            }

            $delivery->markShipped($tracking, new \DateTimeImmutable());
            $this->notifications->notify(
                $participation->getUser(),
                NotificationType::Spedito,
                'Ordine spedito',
                \sprintf('"%s" è stato spedito. Lo riceverai nei prossimi giorni.%s', $campaign->getProduct()->getName(), null !== $tracking ? ' Tracking: '.$tracking.'.' : ''),
                ReferenceType::Prenotazione,
                $participation->getId()->toRfc4122(),
            );

            return ['spedita' => true, 'tracking' => $tracking];
        });
    }

    /** Tracking facoltativo: senza caratteri di controllo, vuoto = assente, al massimo 120 caratteri. */
    private function tracking(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new ApiError('DATI_NON_VALIDI', 'Controlla i campi evidenziati', 422, ['tracking' => 'Il tracking deve essere un testo']);
        }
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
        if (mb_strlen($clean) > self::MAX_TRACKING) {
            throw new ApiError('TRACKING_TROPPO_LUNGO', 'Il tracking può avere al massimo 120 caratteri', 422);
        }

        return '' === $clean ? null : $clean;
    }
}
