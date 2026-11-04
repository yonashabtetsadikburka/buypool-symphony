<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Application\CampaignCancellationService;
use App\Campaign\Application\CampaignClosureService;
use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Service\BalanceExpiryService;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\PaymentIntentData;
use App\Shared\Http\ApiError;
use App\Tests\Support\FakeStripeGateway;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * US-11: chiusura dei gruppi scaduti, addebito dei saldi, rimborsi, annullamento e saldi non pagati.
 *
 * Numeri dei test: scaglioni 10 → 900 e 20 → 800, commissione 10%, 22 unità (scaglione raggiunto: 800).
 * Un cliente con 3 unità e acconto 540 (3 × 900 × 20%) deve 3 × 800 + 240 − 540 = 2100 al ritiro, 2590 a domicilio.
 */
final class CampaignClosureTest extends KernelTestCase
{
    use GroupFixtures;

    private EntityManagerInterface $em;
    private FakeStripeGateway $stripe;
    private CampaignClosureService $closure;
    private CampaignCancellationService $cancellation;
    private BalanceExpiryService $expiry;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->stripe = $container->get(FakeStripeGateway::class);
        $this->closure = $container->get(CampaignClosureService::class);
        $this->cancellation = $container->get(CampaignCancellationService::class);
        $this->expiry = $container->get(BalanceExpiryService::class);
        $this->now = new \DateTimeImmutable();
        $this->em->getConnection()->beginTransaction();
        $this->startFixtures($this->em);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /* ------------------------------ Sotto il minimo ------------------------------ */

    public function testGroupBelowTheMinimumFailsAndEveryDepositIsRefunded(): void
    {
        $campaign = $this->group($this->em, quantity: 6);
        $first = $this->member($this->em, $campaign, ParticipationStatus::Prenotata, quantity: 3);
        $second = $this->member($this->em, $campaign, ParticipationStatus::Prenotata, quantity: 3);

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(2, $result->refunded);
        self::assertTrue($result->closed);
        self::assertSame(CampaignStatus::Fallita, $campaign->getStatus());
        self::assertNotNull($campaign->getClosedAt());
        self::assertSame(ParticipationStatus::Rimborsata, $first->getStatus());
        self::assertSame(ParticipationStatus::Rimborsata, $second->getStatus());
        self::assertCount(2, $this->stripe->refunds);
        self::assertSame([], $this->stripe->charges);
        self::assertSame(PaymentStatus::Rimborsato, $this->paymentOf($first, PaymentType::Acconto)->getStatus());
    }

    public function testFailedRefundKeepsTheGroupOpenAndTheNextRoundOnlyRetriesTheMissingOne(): void
    {
        $campaign = $this->group($this->em, quantity: 6);
        $ok = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $ko = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->stripe->failRefundOf = [$this->paymentOf($ko, PaymentType::Acconto)->getStripePaymentIntentId() ?? ''];

        $first = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $first->refunded);
        self::assertSame(1, $first->refundsFailed);
        self::assertFalse($first->closed);
        self::assertNull($campaign->getClosedAt());
        self::assertSame(ParticipationStatus::Rimborsata, $ok->getStatus());
        self::assertSame(ParticipationStatus::Prenotata, $ko->getStatus());
        self::assertStringStartsWith('rimborso_ko:', (string) $this->paymentOf($ko, PaymentType::Acconto)->getStripeStatus());

        $this->stripe->failRefundOf = [];
        $refundsBefore = \count($this->stripe->refunds);
        $second = $this->closure->close($campaign, $this->now);

        self::assertTrue($second->closed);
        self::assertSame(ParticipationStatus::Rimborsata, $ko->getStatus());
        self::assertCount($refundsBefore + 1, $this->stripe->refunds);   // solo l'adesione mancante
        self::assertNotNull($campaign->getClosedAt());
    }

    public function testBelowMinimumWithBalancesAlreadyPaidCancelsTheGroupAndRefundsEverything(): void
    {
        $campaign = $this->group($this->em, quantity: 6);
        $campaign->setFinalPriceCents(800);   // il prezzo era già stato fissato: qualcuno ha pagato il saldo
        $paid = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->em->persist((new Payment($paid, PaymentType::Saldo, 2100))->setStripePaymentIntentId('pi_saldo_x')->setStatus(PaymentStatus::Confermato));
        $other = $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta);
        $this->em->flush();

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(CampaignStatus::Annullata, $campaign->getStatus());
        self::assertSame(CampaignCancellationService::REASON_UNPAID_BALANCE, $campaign->getCancellationReason());
        self::assertSame(2, $result->refunded);
        self::assertSame(ParticipationStatus::Rimborsata, $paid->getStatus());
        self::assertSame(ParticipationStatus::Rimborsata, $other->getStatus());
        self::assertArrayHasKey('rimborso_pi_saldo_x', $this->stripe->refunds);   // anche il saldo già addebitato
    }

    /* ------------------------------ Minimo raggiunto ------------------------------ */

    public function testReachedMinimumFixesTheHighestTierPriceAndChargesEachBalance(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $pickup = $this->member($this->em, $campaign, ParticipationStatus::Confermata, home: false);
        $home = $this->member($this->em, $campaign, ParticipationStatus::Confermata, home: true);

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(2, $result->paid);
        self::assertTrue($result->closed);
        self::assertSame(800, $campaign->getFinalPriceCents());   // lo scaglione più alto raggiunto, non il primo
        self::assertSame(CampaignStatus::OrdinePronto, $campaign->getStatus());
        self::assertNotNull($campaign->getClosedAt());
        self::assertSame([2100, 2590], array_map(static fn ($c) => $c->amountCents, $this->stripe->charges));
        self::assertSame(ParticipationStatus::Pagata, $pickup->getStatus());
        self::assertSame(ParticipationStatus::Pagata, $home->getStatus());
        self::assertSame(2590, $home->getBalanceCents());
        self::assertSame(240, $home->getCommissionCents());
        $payment = $this->paymentOf($home, PaymentType::Saldo);
        self::assertSame(PaymentStatus::Confermato, $payment->getStatus());
        self::assertSame(2590, $payment->getAmountCents());
    }

    public function testChargeUsesTheSavedCardCustomerAndAnAttemptKey(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata);

        $this->closure->close($campaign, $this->now);

        $charge = $this->stripe->charges[0];
        self::assertSame($member->getUser()->getStripeCustomerId(), $charge->customerId);
        self::assertStringStartsWith('pm_', $charge->paymentMethodId);
        self::assertSame('saldo', $charge->metadata['tipo']);
        self::assertSame($member->getId()->toRfc4122(), $charge->metadata['prenotazione_id']);
        self::assertSame(\sprintf('saldo_%s_2100_1', $member->getId()->toRfc4122()), $charge->idempotencyKey);
    }

    public function testClosingTwiceChargesNothingTheSecondTime(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $this->member($this->em, $campaign, ParticipationStatus::Confermata);

        $this->closure->close($campaign, $this->now);
        $second = $this->closure->close($campaign, $this->now);

        self::assertTrue($second->alreadyClosed);
        self::assertCount(1, $this->stripe->charges);
    }

    public function testBalanceNotCoveredByTheBankMarksActionRequiredWithADeadline(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $good = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $bad = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $this->stripe->chargeFailures[$bad->getId()->toRfc4122()] = 'card_declined';

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $result->paid);
        self::assertSame(1, $result->actionRequired);
        self::assertSame(ParticipationStatus::Pagata, $good->getStatus());
        self::assertSame(ParticipationStatus::AzioneRichiesta, $bad->getStatus());
        // 48 ore per sistemare il pagamento
        self::assertSame($this->now->modify('+48 hours')->format('c'), $bad->getBalanceDueAt()?->format('c'));
        $failed = $this->paymentOf($bad, PaymentType::Saldo);
        self::assertSame(PaymentStatus::Fallito, $failed->getStatus());
        self::assertSame('card_declined', $failed->getStripeStatus());
        // Il gruppo si chiude lo stesso: l'adesione da sistemare non resta "confermata".
        self::assertTrue($result->closed);
        self::assertSame(CampaignStatus::OrdinePronto, $campaign->getStatus());
    }

    public function testMissingSavedCardMarksActionRequiredWithoutCallingStripe(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata, withCard: false);

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $result->actionRequired);
        self::assertSame(ParticipationStatus::AzioneRichiesta, $member->getStatus());
        self::assertSame([], $this->stripe->charges);
    }

    public function testBalanceOfZeroOrLessIsMarkedPaidWithoutChargingAndWithoutRefundingTheSurplus(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata, deposit: 5000);   // acconto > totale

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $result->paid);
        self::assertSame(ParticipationStatus::Pagata, $member->getStatus());
        self::assertSame([], $this->stripe->charges);
        self::assertSame([], $this->stripe->refunds);
        self::assertSame(0, $this->paymentOf($member, PaymentType::Saldo)->getAmountCents());
    }

    public function testPaymentStillProcessingKeepsTheParticipationForTheNextRound(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $this->stripe->chargeStatuses[$member->getId()->toRfc4122()] = 'processing';

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $result->skipped);
        self::assertFalse($result->closed);
        self::assertSame(ParticipationStatus::Confermata, $member->getStatus());
        self::assertNull($campaign->getClosedAt());
    }

    public function testAnAlreadyMadeChargeIsFoundInsteadOfChargingTwice(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $metadata = ['tipo' => 'saldo', 'prenotazione_id' => $member->getId()->toRfc4122(), 'utente_id' => $member->getUser()->getId()->toRfc4122()];
        $this->stripe->existingBalanceIntents[$member->getId()->toRfc4122().'|2100'] = new PaymentIntentData('pi_gia_addebitato', 2100, 'pm_1', 'succeeded', $metadata);

        $result = $this->closure->close($campaign, $this->now);

        self::assertSame(1, $result->paid);
        self::assertSame([], $this->stripe->charges);
        self::assertSame(ParticipationStatus::Pagata, $member->getStatus());
        self::assertSame('pi_gia_addebitato', $this->paymentOf($member, PaymentType::Saldo)->getStripePaymentIntentId());
    }

    /* ------------------------------ Quali gruppi si chiudono ------------------------------ */

    public function testOnlyExpiredGroupsWithoutAClosureDateAreSelected(): void
    {
        $expired = $this->group($this->em, quantity: 6);
        $open = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $alreadyClosed = $this->group($this->em, quantity: 6);
        $alreadyClosed->setClosedAt($this->now->modify('-1 day'));
        $ordered = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);
        $this->em->flush();

        $ids = array_map(static fn ($r) => $r->campaignId, $this->closure->closeExpired($this->now));

        self::assertContains($expired->getId()->toRfc4122(), $ids);
        self::assertNotContains($open->getId()->toRfc4122(), $ids);
        self::assertNotContains($alreadyClosed->getId()->toRfc4122(), $ids);
        self::assertNotContains($ordered->getId()->toRfc4122(), $ids);
        self::assertSame(CampaignStatus::InCorso, $open->getStatus());
    }

    public function testACancelledGroupWithRefundsLeftIsResumed(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::Annullata);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata);

        $ids = array_map(static fn ($r) => $r->campaignId, $this->closure->closeExpired($this->now));

        self::assertContains($campaign->getId()->toRfc4122(), $ids);
        self::assertSame(ParticipationStatus::Rimborsata, $member->getStatus());
        self::assertNotNull($campaign->getClosedAt());
    }

    /* ------------------------------ Annullamento ------------------------------ */

    public function testCancellingRefundsDepositAndBalanceOfEveryone(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '+5 days');
        $paid = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->em->persist((new Payment($paid, PaymentType::Saldo, 2100))->setStripePaymentIntentId('pi_saldo_y')->setStatus(PaymentStatus::Confermato));
        $reserved = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->em->flush();

        $result = $this->cancellation->cancel($campaign, 'Il fornitore ha chiuso', null, $this->now);

        self::assertTrue($result->complete);
        self::assertSame(2, $result->refunded);
        self::assertSame(CampaignStatus::Annullata, $campaign->getStatus());
        self::assertSame('Il fornitore ha chiuso', $campaign->getCancellationReason());
        self::assertNotNull($campaign->getClosedAt());
        self::assertSame(ParticipationStatus::Rimborsata, $paid->getStatus());
        self::assertSame(ParticipationStatus::Rimborsata, $reserved->getStatus());
        self::assertArrayHasKey('rimborso_pi_saldo_y', $this->stripe->refunds);
    }

    public function testCancellingTwiceRefundsOnlyOnce(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+5 days');
        $this->member($this->em, $campaign, ParticipationStatus::Prenotata);

        $this->cancellation->cancel($campaign, 'Motivo valido', null, $this->now);
        $count = \count($this->stripe->refunds);
        $second = $this->cancellation->cancel($campaign, 'Motivo valido', null, $this->now);

        self::assertSame(0, $second->refunded);
        self::assertCount($count, $this->stripe->refunds);
    }

    public function testAGroupWithAnOrderAtTheSupplierCannotBeCancelled(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);

        try {
            $this->cancellation->cancel($campaign, 'Motivo valido', null, $this->now);
            self::fail('Doveva rifiutare.');
        } catch (ApiError $e) {
            self::assertSame('GRUPPO_NON_ANNULLABILE', $e->codice);
            self::assertSame(409, $e->httpStatus);
        }
    }

    /* ------------------------------ Saldo non pagato entro il termine ------------------------------ */

    public function testExpiredBalanceCancelsTheParticipationRefundsTheDepositAndLowersTheQuantity(): void
    {
        $campaign = $this->group($this->em, quantity: 25);
        $campaign->setFinalPriceCents(800);
        $stays = $this->member($this->em, $campaign, ParticipationStatus::Pagata, quantity: 20, deposit: 3600);
        $late = $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta, quantity: 5, deposit: 900);
        $late->setBalanceDueAt($this->now->modify('-1 hour'));
        $this->em->flush();

        $result = $this->expiry->expire($this->now);

        self::assertSame(1, $result->cancelledParticipations);
        self::assertSame(0, $result->cancelledGroups);
        self::assertSame(ParticipationStatus::Rimborsata, $late->getStatus());
        self::assertSame(ParticipationStatus::Pagata, $stays->getStatus());
        self::assertSame(20, $campaign->getCurrentQuantity());
        self::assertSame(800, $campaign->getFinalPriceCents());   // il prezzo degli altri non cambia
        self::assertArrayHasKey('rimborso_'.($this->paymentOf($late, PaymentType::Acconto)->getStripePaymentIntentId()), $this->stripe->refunds);
        self::assertNotSame(CampaignStatus::Annullata, $campaign->getStatus());
    }

    public function testExpiredBalanceThatDropsTheGroupBelowTheMinimumCancelsTheGroupAndRefundsEveryone(): void
    {
        $campaign = $this->group($this->em, quantity: 12, status: CampaignStatus::Riuscita);
        $campaign->setFinalPriceCents(900);
        $paid = $this->member($this->em, $campaign, ParticipationStatus::Pagata, quantity: 6, deposit: 1080);
        $this->em->persist((new Payment($paid, PaymentType::Saldo, 4000))->setStripePaymentIntentId('pi_saldo_z')->setStatus(PaymentStatus::Confermato));
        $late = $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta, quantity: 6, deposit: 1080);
        $late->setBalanceDueAt($this->now->modify('-1 minute'));
        $this->em->flush();

        $result = $this->expiry->expire($this->now);

        self::assertSame(1, $result->cancelledGroups);
        self::assertSame(CampaignStatus::Annullata, $campaign->getStatus());
        self::assertSame(CampaignCancellationService::REASON_UNPAID_BALANCE, $campaign->getCancellationReason());
        self::assertSame(ParticipationStatus::Rimborsata, $paid->getStatus());
        self::assertArrayHasKey('rimborso_pi_saldo_z', $this->stripe->refunds);   // anche chi aveva già pagato il saldo
    }

    public function testBalanceStillWithinItsDeadlineIsLeftAlone(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $campaign->setFinalPriceCents(800);
        $waiting = $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta);
        $waiting->setBalanceDueAt($this->now->modify('+10 hours'));
        $this->em->flush();

        $result = $this->expiry->expire($this->now);

        self::assertSame(0, $result->cancelledParticipations);
        self::assertSame(ParticipationStatus::AzioneRichiesta, $waiting->getStatus());
        self::assertSame([], $this->stripe->refunds);
    }

    public function testFailedDepositRefundKeepsTheParticipationWaitingForTheNextRound(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $campaign->setFinalPriceCents(800);
        $late = $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta);
        $late->setBalanceDueAt($this->now->modify('-1 hour'));
        $this->em->flush();
        $this->stripe->failRefunds = true;

        $first = $this->expiry->expire($this->now);

        self::assertSame(1, $first->refundsFailed);
        self::assertSame(ParticipationStatus::AzioneRichiesta, $late->getStatus());
        self::assertSame(22, $campaign->getCurrentQuantity());

        $this->stripe->failRefunds = false;
        $second = $this->expiry->expire($this->now);

        self::assertSame(1, $second->cancelledParticipations);
        self::assertSame(ParticipationStatus::Rimborsata, $late->getStatus());
    }

    public function testBalancePaidInTimeIsNotRefundedEvenIfItWasLateInTheList(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $campaign->setFinalPriceCents(800);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata);   // nel frattempo ha pagato
        $member->setBalanceDueAt($this->now->modify('-1 hour'));
        $this->em->flush();

        $result = $this->expiry->expire($this->now);

        self::assertSame(0, $result->cancelledParticipations);
        self::assertSame(ParticipationStatus::Pagata, $member->getStatus());
        self::assertSame([], $this->stripe->refunds);
    }

    private function paymentOf(Participation $participation, PaymentType $type): Payment
    {
        $payments = $this->em->getRepository(Payment::class)->findBy(['participation' => $participation, 'type' => $type], ['createdAt' => 'DESC']);

        return $payments[0] ?? self::fail('Pagamento non trovato.');
    }
}
