<?php

namespace App\Campaign\Application;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Repository\DeliveryRepository;
use App\Delivery\Service\PickupService;
use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Merce arrivata" (legacy: admin_gruppo_merce_arrivata_esegui): il gruppo passa da "ordine al fornitore" a "consegnata".
 * Per ogni adesione pagata col ritiro in sede nasce il codice QR e il cliente riceve la notifica "pronto per il ritiro";
 * le spedizioni a domicilio restano da spedire.
 */
final class GoodsArrivalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly CampaignTransitioner $transitioner,
        private readonly SupplierOrderRepository $orders,
        private readonly ParticipationRepository $participations,
        private readonly DeliveryRepository $deliveries,
        private readonly PickupService $pickup,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array{ritiri: int, spedizioni: int}
     */
    public function markArrived(Campaign $campaign, ?User $admin): array
    {
        return $this->transaction->run(function () use ($campaign, $admin): array {
            $locked = $this->em->find(Campaign::class, $campaign->getId(), LockMode::PESSIMISTIC_WRITE) ?? $campaign;
            $this->em->refresh($locked);

            if (CampaignStatus::OrdineFornitore !== $locked->getStatus()) {
                throw new ApiError('STATO_NON_VALIDO', 'Il gruppo deve essere in stato "ordine_fornitore". Stato attuale: '.$locked->getStatus()->value, 409);
            }
            $order = $this->orders->findOneBy(['campaign' => $locked]);
            if (null === $order) {
                throw new ApiError('ORDINE_FORNITORE_MANCANTE', 'Manca l\'ordine al fornitore di questo gruppo', 409);
            }
            if (\in_array($order->getStatus(), [SupplierOrderStatus::Inviato, SupplierOrderStatus::Ricevuto, SupplierOrderStatus::InPreparazione], true)) {
                $order->setStatus(SupplierOrderStatus::Evaso);
            }
            $order->setDeliveredAt($order->getDeliveredAt() ?? new \DateTimeImmutable());

            $this->transitioner->apply($locked, CampaignTransition::Deliver, $admin);

            $pickups = 0;
            $shipments = 0;
            foreach ($this->participations->findByCampaignAndStatuses($locked, [ParticipationStatus::Pagata]) as $participation) {
                $delivery = $this->deliveries->findOneBy(['participation' => $participation]);
                if (DeliveryMode::ConsegnaDomicilio === $delivery?->getMode()) {
                    ++$shipments;
                    continue;
                }
                $this->pickup->createFor($participation);
                if (DeliveryStatus::InAttesa === $delivery?->getStatus()) {
                    $delivery->setStatus(DeliveryStatus::Pronta);
                }
                $point = $delivery?->getPickupPoint() ?? $locked->getPickupPoint();
                $this->notifications->notify(
                    $participation->getUser(),
                    NotificationType::MercePronta,
                    'Pronto per il ritiro',
                    \sprintf(
                        'Il tuo ordine "%s" è pronto per il ritiro%s. Mostra il codice QR dalla pagina dell\'ordine.',
                        $locked->getProduct()->getName(),
                        null !== $point ? ' presso '.$point->getName().', '.$point->getAddress().' ('.$point->getCity().')' : '',
                    ),
                    ReferenceType::Prenotazione,
                    $participation->getId()->toRfc4122(),
                );
                ++$pickups;
            }

            return ['ritiri' => $pickups, 'spedizioni' => $shipments];
        });
    }
}
