<?php

namespace App\Participation\Service;

use App\Campaign\Application\CampaignCancellationService;
use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Repository\CampaignRepository;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Enum\PaymentType;
use App\Payment\Service\RefundService;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Saldo non pagato entro il termine (di norma 48 ore): l'adesione è annullata e l'acconto rimborsato; se il gruppo
 * scende così sotto il minimo viene annullato e rimborsati tutti (legacy: saldi_scaduti_esegui, gruppo_valuta_dopo_annullo).
 * È anche il giro che riprende i rimborsi rimasti a metà.
 *
 * Non ancora portati: il promemoria a 24 ore (servono le notifiche, US-09/US-23), la riprova da parte del cliente
 * (US-26) e il "costo a carico di BuyPool" per il cambio di scaglione (US-27).
 */
final class BalanceExpiryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly ParticipationRepository $participations,
        private readonly CampaignRepository $campaigns,
        private readonly CampaignCancellationService $cancellation,
        private readonly RefundService $refunds,
        private readonly PricingCalculator $pricing,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function expire(\DateTimeImmutable $now): ExpiryResult
    {
        $cancelledParticipations = 0;
        $cancelledGroups = 0;
        $refundsFailed = 0;
        $touched = [];

        foreach ($this->participations->findWithExpiredBalance($now) as $participation) {
            $participationId = $participation->getId();
            /** @var array{0: ?Campaign, 1: bool} $outcome campagna toccata (null = nulla da fare) e rimborso incompleto */
            $outcome = $this->transaction->run(function () use ($participationId): array {
                // Riga bloccata e stato ricontrollato PRIMA di rimborsare: se nel frattempo ha pagato, non si rimborsa.
                $locked = $this->em->find(Participation::class, $participationId, LockMode::PESSIMISTIC_WRITE);
                if (null === $locked || ParticipationStatus::AzioneRichiesta !== $locked->getStatus()) {
                    return [null, false];
                }
                $refund = $this->refunds->refund($locked, [PaymentType::Acconto]);
                if (!$refund->isComplete()) {
                    // L'adesione NON diventa "rimborsata": si riprova al giro dopo (i rimborsi già riusciti restano salvati).
                    return [null, true];
                }
                $locked->setStatus(ParticipationStatus::Rimborsata)->setBalanceDueAt(null);
                $campaign = $locked->getCampaign();
                $campaign->setCurrentQuantity(max(0, $campaign->getCurrentQuantity() - $locked->getQuantity()));

                return [$campaign, false];
            });

            [$campaign, $incomplete] = $outcome;
            if ($incomplete) {
                ++$refundsFailed;
                continue;
            }
            if (null === $campaign) {
                $this->logger->info('Adesione non più in attesa del saldo: saltata senza rimborso', ['adesione' => $participationId->toRfc4122()]);
                continue;
            }
            ++$cancelledParticipations;
            $touched[$campaign->getId()->toRfc4122()] = $campaign;
        }

        // Rivaluta ogni gruppo toccato: il prezzo è già fissato (la chiusura è iniziata) e le unità sono scese.
        foreach ($touched as $campaign) {
            if (null === $campaign->getFinalPriceCents()) {
                continue;
            }
            if (!\in_array($campaign->getStatus(), [CampaignStatus::InCorso, CampaignStatus::Riuscita, CampaignStatus::OrdinePronto], true)) {
                continue;
            }
            if ($this->pricing->isBelowMinimum($campaign->getPriceTiers(), $campaign->getMoq(), $campaign->getCurrentQuantity())) {
                $result = $this->cancelGroup($campaign, $now);
                ++$cancelledGroups;
                $refundsFailed += $result;
            }
        }

        // Riprende i gruppi annullati con rimborsi rimasti a metà.
        foreach ($this->campaigns->findCancelledWithLiveParticipations() as $campaign) {
            if (isset($touched[$campaign->getId()->toRfc4122()])) {
                continue;
            }
            $refundsFailed += $this->cancelGroup($campaign, $now);
        }

        return new ExpiryResult($cancelledParticipations, $cancelledGroups, $refundsFailed);
    }

    /** @return int rimborsi falliti */
    private function cancelGroup(Campaign $campaign, \DateTimeImmutable $now): int
    {
        try {
            return $this->cancellation->cancel($campaign, CampaignCancellationService::REASON_UNPAID_BALANCE, null, $now)->failed;
        } catch (ApiError $e) {
            $this->logger->warning('Annullamento del gruppo non possibile ora', ['gruppo' => $campaign->getId()->toRfc4122(), 'codice' => $e->codice]);

            return 0;
        }
    }
}
