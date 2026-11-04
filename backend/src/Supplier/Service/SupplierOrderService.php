<?php

namespace App\Supplier\Service;

use App\Campaign\Application\CampaignTransitioner;
use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Identity\Entity\User;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Invio dell'ordine al fornitore (legacy: campagne_invia_fornitore, invia_ordine_fornitore_al).
 * Possibile una sola volta: il gruppo deve essere "ordine pronto" e ogni saldo già pagato (o l'adesione rimborsata),
 * perché la quantità da ordinare deve essere definitiva. Una seconda richiesta trova il gruppo già "ordine al fornitore": 409.
 */
final class SupplierOrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly CampaignTransitioner $transitioner,
        private readonly ParticipationRepository $participations,
        private readonly SupplierOrderRepository $orders,
        private readonly PricingCalculator $pricing,
    ) {
    }

    /**
     * @return array{totale_unita: int, importo_ordine_cents: int, numero_ordine: string}
     */
    public function send(Campaign $campaign, ?User $admin): array
    {
        return $this->transaction->run(function () use ($campaign, $admin): array {
            $locked = $this->em->find(Campaign::class, $campaign->getId(), LockMode::PESSIMISTIC_WRITE) ?? $campaign;
            $this->em->refresh($locked);

            if (CampaignStatus::OrdinePronto !== $locked->getStatus()) {
                throw new ApiError('STATO_NON_VALIDO', 'Il gruppo deve essere in stato "ordine_pronto". Stato attuale: '.$locked->getStatus()->value, 409);
            }

            // Tutte le adesioni pagate o rimborsate: finché ci sono saldi in attesa la quantità non è definitiva.
            $pending = 0;
            foreach ($this->participations->findByCampaign($locked) as $participation) {
                if (!\in_array($participation->getStatus(), [ParticipationStatus::Pagata, ParticipationStatus::Rimborsata], true)) {
                    ++$pending;
                }
            }
            if ($pending > 0) {
                throw new ApiError('PAGAMENTI_INCOMPLETI', 1 === $pending
                    ? "Non puoi segnare l'ordine come inviato: c'è 1 saldo in attesa o non riuscito. La quantità da ordinare deve essere definitiva."
                    : \sprintf("Non puoi segnare l'ordine come inviato: ci sono %d saldi in attesa o non riusciti. La quantità da ordinare deve essere definitiva.", $pending), 409);
            }

            // Unità al prezzo fissato alla chiusura (se manca, lo scaglione raggiunto).
            $quantity = $locked->getCurrentQuantity();
            if ($quantity <= 0) {
                throw new ApiError('STATO_NON_VALIDO', 'Nessuna unità da ordinare', 409);
            }
            $unitPrice = $this->pricing->balancePrice($locked->getFinalPriceCents(), $locked->getPriceTiers(), $quantity, $locked->getCurrentPriceCents());
            $total = $quantity * $unitPrice;

            $order = $this->orders->findOneBy(['campaign' => $locked]);
            if (null === $order) {
                $order = new SupplierOrder(
                    $locked,
                    $locked->getProduct()->getSupplier(),
                    \sprintf('ORD-%s-%s', date('Y'), strtoupper(substr(str_replace('-', '', $locked->getId()->toRfc4122()), -8))),
                    (int) date('Y'),
                    $quantity,
                    $total,
                );
                $this->em->persist($order);
            }
            $order->setAdmin($admin)->setStatus(SupplierOrderStatus::Inviato);

            $this->transitioner->apply($locked, CampaignTransition::SendToSupplier, $admin);

            return ['totale_unita' => $quantity, 'importo_ordine_cents' => $total, 'numero_ordine' => $order->getOrderNumber()];
        });
    }
}
