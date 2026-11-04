<?php

namespace App\Delivery\Service;

use App\Delivery\Entity\Delivery;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Conferma di ricezione di una spedizione a domicilio da parte del cliente (legacy: consegne_conferma_ricezione).
 * Solo il proprietario (403 agli altri), solo spedizioni a domicilio già spedite; la seconda conferma dà 409.
 */
final class DeliveryReceiptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array{consegnata: bool}
     */
    public function confirm(User $user, string $deliveryId): array
    {
        if (!Uuid::isValid($deliveryId)) {
            throw new ApiError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
        }

        return $this->transaction->run(function () use ($user, $deliveryId): array {
            $delivery = $this->em->find(Delivery::class, Uuid::fromString($deliveryId), LockMode::PESSIMISTIC_WRITE);
            if (!$delivery instanceof Delivery) {
                throw new ApiError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
            }
            $this->em->refresh($delivery);
            $participation = $delivery->getParticipation();
            if (!$participation->getUser()->getId()->equals($user->getId())) {
                throw new ApiError('NON_AUTORIZZATO', 'Non puoi confermare la ricezione di un altro utente', 403);
            }
            if (DeliveryMode::ConsegnaDomicilio !== $delivery->getMode()) {
                throw new ApiError('NON_CONFERMABILE', 'Solo le spedizioni a domicilio si possono confermare');
            }
            if (DeliveryStatus::Spedita !== $delivery->getStatus()) {
                throw new ApiError('NON_CONFERMABILE', 'La consegna deve essere spedita prima di confermare la ricezione', 409);
            }

            $delivery->setStatus(DeliveryStatus::Consegnata)->setDeliveredAt(new \DateTimeImmutable());
            $this->notifications->notify(
                $user,
                NotificationType::OrdineRicevuto,
                'Ordine Ricevuto',
                \sprintf('Hai confermato la ricezione di "%s". Grazie per il tuo acquisto!', $participation->getCampaign()->getProduct()->getName()),
                ReferenceType::Prenotazione,
                $participation->getId()->toRfc4122(),
            );

            return ['consegnata' => true];
        });
    }
}
