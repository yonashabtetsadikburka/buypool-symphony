<?php

namespace App\Campaign\Application;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Campaign\Repository\CampaignRepository;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Participation\Service\BalanceService;
use App\Participation\Service\PaymentOutcome;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\OffSessionCharge;
use App\Payment\Gateway\PaymentIntentData;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use App\Payment\Repository\PaymentRepository;
use App\Payment\Service\RefundService;
use App\Pricing\PricingCalculator;
use App\Shared\Persistence\NamedLock;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiusura dei gruppi scaduti (US-11; legacy: campagne_chiusura, chiusura_colletta, chiusura_solo_rimborsi).
 *
 *  - Sotto il minimo: il gruppo diventa "fallita" e gli acconti vengono rimborsati. Se qualcuno aveva già pagato il
 *    saldo (prezzo già fissato) il gruppo è annullato e rimborsato per intero.
 *  - Minimo raggiunto: si fissa il prezzo dello scaglione più alto raggiunto (una volta sola), si addebita il saldo a
 *    ogni adesione confermata con la carta salvata e, quando nessuna è più da addebitare, il gruppo passa a "ordine pronto".
 *
 * Ripetibile in sicurezza: la data di chiusura si scrive solo a lavoro finito, un solo processo alla volta lavora su un
 * gruppo e un addebito già fatto su Stripe viene ritrovato invece di essere ripetuto.
 */
final class CampaignClosureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly NamedLock $locks,
        private readonly CampaignRepository $campaigns,
        private readonly ParticipationRepository $participations,
        private readonly PaymentRepository $payments,
        private readonly CampaignTransitioner $transitioner,
        private readonly CampaignCancellationService $cancellation,
        private readonly BalanceService $balances,
        private readonly RefundService $refunds,
        private readonly PricingCalculator $pricing,
        private readonly StripeGateway $stripe,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.balance.payment_deadline_hours%')]
        private readonly int $paymentDeadlineHours,
    ) {
    }

    /** Nome del blocco che serializza chiusura e annullamento dello stesso gruppo. */
    public static function lockName(Campaign $campaign): string
    {
        return 'campaign_closure_'.$campaign->getId()->toRfc4122();
    }

    /**
     * Chiude tutti i gruppi scaduti ancora aperti (è quello che fa il comando pianificato).
     *
     * @return list<ClosureResult>
     */
    public function closeExpired(\DateTimeImmutable $now): array
    {
        $results = [];
        foreach ($this->campaigns->findToClose($now) as $campaign) {
            $results[] = $this->close($campaign, $now);
        }

        return $results;
    }

    public function close(Campaign $campaign, \DateTimeImmutable $now): ClosureResult
    {
        $result = $this->locks->runExclusive(self::lockName($campaign), fn (): ClosureResult => $this->doClose($campaign, $now));
        if (null === $result) {
            $this->logger->warning('Chiusura già in corso in un altro processo, gruppo saltato', ['gruppo' => $campaign->getId()->toRfc4122()]);

            return new ClosureResult($campaign->getId()->toRfc4122(), skipped: 1);
        }

        return $result;
    }

    private function doClose(Campaign $campaign, \DateTimeImmutable $now): ClosureResult
    {
        $this->em->refresh($campaign);
        $id = $campaign->getId()->toRfc4122();
        if (null !== $campaign->getClosedAt()) {
            return new ClosureResult($id, alreadyClosed: true);
        }

        $status = $campaign->getStatus();
        if (CampaignStatus::Annullata === $status) {
            $cancelled = $this->cancellation->cancel($campaign, null, null, $now);

            return new ClosureResult($id, refunded: $cancelled->refunded, refundsFailed: $cancelled->failed, skipped: $cancelled->failed > 0 ? 1 : 0, closed: $cancelled->complete);
        }
        if (!\in_array($status, [CampaignStatus::InCorso, CampaignStatus::Riuscita, CampaignStatus::Fallita], true)) {
            return new ClosureResult($id, skipped: 1);   // ordine pronto, al fornitore, consegnato: già oltre la chiusura
        }

        if ($campaign->getCurrentQuantity() < $campaign->getMoq()) {
            return $this->closeBelowMinimum($campaign, $now);
        }

        return $this->closeWithBalances($campaign, $now);
    }

    private function closeBelowMinimum(Campaign $campaign, \DateTimeImmutable $now): ClosureResult
    {
        $id = $campaign->getId()->toRfc4122();

        // Il prezzo era già stato fissato: qualcuno ha pagato il saldo, quindi si rimborsa TUTTO e il gruppo è annullato.
        if (null !== $campaign->getFinalPriceCents()) {
            $cancelled = $this->cancellation->cancel($campaign, CampaignCancellationService::REASON_UNPAID_BALANCE, null, $now);

            return new ClosureResult($id, refunded: $cancelled->refunded, refundsFailed: $cancelled->failed, skipped: $cancelled->failed > 0 ? 1 : 0, closed: $cancelled->complete);
        }

        if (CampaignStatus::Fallita !== $campaign->getStatus()) {
            $this->transaction->run(fn () => $this->transitioner->apply($campaign, CampaignTransition::FailOnDeadline));
        }

        $refunded = 0;
        $failed = 0;
        $toRefund = $this->participations->findByCampaignAndStatuses($campaign, [ParticipationStatus::Prenotata, ParticipationStatus::Confermata]);
        foreach ($toRefund as $participation) {
            if ($participation->getDepositCents() <= 0) {
                continue;
            }
            $done = $this->transaction->run(function () use ($participation): bool {
                $result = $this->refunds->refund($participation, [PaymentType::Acconto]);
                if ($result->isComplete()) {
                    $participation->setStatus(ParticipationStatus::Rimborsata);
                }

                return $result->isComplete();
            });
            $done ? ++$refunded : ++$failed;
        }

        // La data di chiusura si scrive solo se tutti i rimborsi sono riusciti: altrimenti si riprende al prossimo giro.
        $closed = 0 === $failed;
        if ($closed) {
            $this->transaction->run(fn () => $campaign->setClosedAt($now));
        }

        return new ClosureResult($id, refunded: $refunded, refundsFailed: $failed, skipped: $failed > 0 ? 1 : 0, closed: $closed);
    }

    private function closeWithBalances(Campaign $campaign, \DateTimeImmutable $now): ClosureResult
    {
        $id = $campaign->getId()->toRfc4122();

        $this->transaction->run(function () use ($campaign): void {
            if (CampaignStatus::Fallita === $campaign->getStatus()) {
                return;
            }
            if (CampaignStatus::InCorso === $campaign->getStatus()) {
                $this->transitioner->apply($campaign, CampaignTransition::ReachMoq);
            }
            // Prezzo FISSATO alla chiusura: lo scaglione più alto raggiunto, scritto una volta sola. Un giro successivo
            // lo rilegge uguale anche se nel frattempo un'adesione è stata annullata e le unità sono scese.
            if (null === $campaign->getFinalPriceCents()) {
                $campaign->setFinalPriceCents($this->pricing->reachedTierPrice($campaign->getPriceTiers(), $campaign->getCurrentQuantity(), $campaign->getCurrentPriceCents()));
            }
        });
        if (CampaignStatus::Fallita === $campaign->getStatus()) {
            return $this->closeBelowMinimum($campaign, $now);
        }

        $paid = 0;
        $actionRequired = 0;
        $skipped = 0;
        foreach ($this->participations->findByCampaignAndStatuses($campaign, [ParticipationStatus::Confermata]) as $participation) {
            switch ($this->chargeBalance($participation, $now)) {
                case 'paid':
                    ++$paid;
                    break;
                case 'action':
                    ++$actionRequired;
                    break;
                default:
                    ++$skipped;
            }
        }

        // Il gruppo si chiude solo se nessuna adesione è rimasta "confermata" (altrimenti il prossimo giro la riprende).
        $remaining = $this->participations->findByCampaignAndStatuses($campaign, [ParticipationStatus::Confermata]);
        $closed = [] === $remaining;
        if ($closed) {
            $this->transaction->run(function () use ($campaign, $now): void {
                $this->transitioner->apply($campaign, CampaignTransition::Allocate);
                $campaign->setClosedAt($now);
            });
        } else {
            $this->logger->info('Chiusura rimandata: adesioni ancora da addebitare', ['gruppo' => $id, 'restanti' => \count($remaining)]);
        }

        return new ClosureResult($id, paid: $paid, actionRequired: $actionRequired, skipped: $skipped, closed: $closed);
    }

    /**
     * Addebita il saldo di una adesione confermata. Esito: "paid", "action" (da sistemare dal cliente) o "skipped".
     */
    private function chargeBalance(Participation $participation, \DateTimeImmutable $now): string
    {
        $cents = $this->balances->breakdown($participation)->balanceCents;

        if ($cents <= 0) {
            $this->transaction->run(fn () => $this->balances->completeWithoutCharge($participation));

            return 'paid';
        }

        $customerId = $participation->getUser()->getStripeCustomerId();
        $method = $this->payments->findLatestDepositMethod($participation);
        if (null === $customerId || '' === $customerId || null === $method) {
            $this->transaction->run(fn () => $this->markActionRequired($participation, $now));

            return 'action';
        }

        $participationId = $participation->getId()->toRfc4122();
        $campaignId = $participation->getCampaign()->getId()->toRfc4122();
        $userId = $participation->getUser()->getId()->toRfc4122();

        try {
            // Se un giro precedente ha addebitato ma non ha finito di salvare, l'addebito esiste già: si ritrova, non si ripete.
            $intent = $this->stripe->findBalanceIntent($customerId, $participationId, $cents);
        } catch (StripeGatewayException) {
            $this->logger->error('Verifica degli addebiti esistenti non riuscita: meglio non addebitare ora', ['adesione' => $participationId]);

            return 'skipped';
        }

        if (null === $intent) {
            $attempt = 1 + $this->payments->countBalanceAttempts($participation);
            try {
                // La chiave identifica il TENTATIVO (importo e numero): Stripe ricorda anche le risposte d'errore.
                $intent = $this->stripe->chargeBalanceOffSession(new OffSessionCharge(
                    $customerId,
                    $method,
                    $cents,
                    ['tipo' => 'saldo', 'prenotazione_id' => $participationId, 'colletta_id' => $campaignId, 'utente_id' => $userId],
                    \sprintf('saldo_%s_%d_%d', $participationId, $cents, $attempt),
                ));
            } catch (StripeGatewayException $e) {
                $this->transaction->run(function () use ($participation, $cents, $e, $now): void {
                    $this->registerFailure($participation, $cents, $e->paymentIntentId, $e->stripeCode ?? 'errore');
                    $this->markActionRequired($participation, $now);
                });

                return 'action';
            }
        }

        if ('succeeded' === $intent->status) {
            return PaymentOutcome::Completed === $this->balances->complete($intent) ? 'paid' : 'skipped';
        }
        if (\in_array($intent->status, ['processing', 'requires_capture'], true)) {
            // Esito non ancora definitivo: lo chiude il webhook payment_intent.succeeded o il prossimo giro.
            return 'skipped';
        }

        $this->transaction->run(function () use ($participation, $cents, $intent, $now): void {
            $this->registerFailure($participation, $cents, $intent->id, 'stato_'.$intent->status);
            $this->markActionRequired($participation, $now);
        });

        return 'action';
    }

    /** Il cliente ha un termine (di norma 48 ore) per sistemare il pagamento; poi l'adesione è annullata e l'acconto rimborsato. */
    private function markActionRequired(Participation $participation, \DateTimeImmutable $now): void
    {
        $participation->setStatus(ParticipationStatus::AzioneRichiesta);
        if (null === $participation->getBalanceDueAt()) {
            $participation->setBalanceDueAt($now->modify(\sprintf('+%d hours', max(1, $this->paymentDeadlineHours))));
        }
    }

    /** Tentativo di saldo non riuscito: una riga "fallito" (unica per PaymentIntent) con il solo codice dell'errore. */
    private function registerFailure(Participation $participation, int $amountCents, ?string $intentId, string $code): void
    {
        if (null !== $intentId && '' !== $intentId && null !== $this->payments->findOneByStripePaymentIntentId($intentId)) {
            return;
        }
        $this->em->persist((new Payment($participation, PaymentType::Saldo, $amountCents))
            ->setStripePaymentIntentId('' === (string) $intentId ? null : $intentId)
            ->setStripeStatus(mb_substr($code, 0, 50))
            ->setStatus(PaymentStatus::Fallito));
    }
}
