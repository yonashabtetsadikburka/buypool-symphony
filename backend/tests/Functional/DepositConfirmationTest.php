<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Service\DepositConfirmationService;
use App\Participation\Service\DepositOutcome;
use App\Participation\Service\PaidDeposit;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\StripeGatewayException;
use App\Supplier\Entity\Supplier;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * US-04: un acconto pagato diventa un'adesione (e aumenta la quantità del gruppo) — una sola volta.
 */
final class DepositConfirmationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DepositConfirmationService $service;
    private FakeStripeGateway $stripe;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(DepositConfirmationService::class);
        $this->stripe = $container->get(FakeStripeGateway::class);
        $this->em->getConnection()->beginTransaction();

        $this->supplier = new Supplier('Fornitore conferma test');
        $this->pickupPoint = new PickupPoint('Sede conferma test', 'Via Prova 1', 'Perugia');
        $this->em->persist($this->supplier);
        $this->em->persist($this->pickupPoint);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testPaidDepositCreatesTheParticipationDeliveryAndPayment(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();

        $outcome = $this->service->confirm($this->paid($campaign, $user, quantity: 3, amount: 540, pi: 'pi_1'));

        self::assertSame(DepositOutcome::Created, $outcome);
        $participation = $this->participation($campaign, $user);
        self::assertSame(3, $participation->getQuantity());
        self::assertSame(540, $participation->getDepositCents());
        self::assertSame(ParticipationStatus::Prenotata, $participation->getStatus());

        $payment = $this->em->getRepository(Payment::class)->findOneBy(['participation' => $participation]);
        self::assertNotNull($payment);
        self::assertSame(PaymentType::Acconto, $payment->getType());
        self::assertSame(PaymentStatus::Confermato, $payment->getStatus());
        self::assertSame(540, $payment->getAmountCents());
        self::assertSame('pi_1', $payment->getStripePaymentIntentId());
        self::assertNotNull($payment->getConfirmedAt());

        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $participation]);
        self::assertNotNull($delivery);
        self::assertSame(DeliveryMode::RitiroSede, $delivery->getMode());
        self::assertSame(0, $delivery->getDeliveryCostCents());
        self::assertSame($this->pickupPoint->getId()->toRfc4122(), $delivery->getPickupPoint()?->getId()->toRfc4122());
    }

    public function testCurrentQuantityIsIncreasedByTheJoinedUnits(): void
    {
        $campaign = $this->campaign(moq: 10);

        $this->service->confirm($this->paid($campaign, $this->customer(), quantity: 3, amount: 540, pi: 'pi_1'));
        $this->service->confirm($this->paid($campaign, $this->customer(), quantity: 2, amount: 360, pi: 'pi_2'));

        $this->em->refresh($campaign);
        self::assertSame(5, $campaign->getCurrentQuantity());
        self::assertSame(CampaignStatus::InCorso, $campaign->getStatus());
    }

    public function testHomeDeliveryStoresTheAddressAndTheShippingCost(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();

        $this->service->confirm($this->paid($campaign, $user, quantity: 1, amount: 200, pi: 'pi_h', delivery: ['delivery_type' => 'consegna_domicilio', 'id_sede' => '', 'indirizzo' => 'Via Roma 1, 06100 Perugia (PG)']));

        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $this->participation($campaign, $user)]);
        self::assertNotNull($delivery);
        self::assertSame(DeliveryMode::ConsegnaDomicilio, $delivery->getMode());
        self::assertSame(490, $delivery->getDeliveryCostCents());
        self::assertSame('Via Roma 1, 06100 Perugia (PG)', $delivery->getDeliveryAddress());
        self::assertNull($delivery->getPickupPoint());
    }

    public function testTheSamePaymentIsHandledOnlyOnce(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();
        $paid = $this->paid($campaign, $user, quantity: 2, amount: 400, pi: 'pi_dup');

        self::assertSame(DepositOutcome::Created, $this->service->confirm($paid));
        self::assertSame(DepositOutcome::AlreadyHandled, $this->service->confirm($paid));

        $this->em->refresh($campaign);
        self::assertSame(2, $campaign->getCurrentQuantity());
        self::assertSame(1, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
        self::assertSame([], $this->stripe->refunds);
    }

    public function testASecondDepositFromTheSameCustomerIsRefundedInFull(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();
        $this->service->confirm($this->paid($campaign, $user, quantity: 2, amount: 400, pi: 'pi_first'));

        $outcome = $this->service->confirm($this->paid($campaign, $user, quantity: 2, amount: 400, pi: 'pi_second'));

        self::assertSame(DepositOutcome::Refunded, $outcome);
        self::assertSame(['rimborso_duplicato_pi_second' => 'pi_second'], $this->stripe->refunds);
        $this->em->refresh($campaign);
        self::assertSame(2, $campaign->getCurrentQuantity());
        $row = $this->em->getRepository(Payment::class)->findOneBy(['stripePaymentIntentId' => 'pi_second']);
        self::assertNotNull($row);
        self::assertSame(PaymentStatus::Rimborsato, $row->getStatus());
        // Evento ripetuto: nessun secondo rimborso.
        self::assertSame(DepositOutcome::AlreadyHandled, $this->service->confirm($this->paid($campaign, $user, quantity: 2, amount: 400, pi: 'pi_second')));
    }

    public function testLatePaymentOnAnExpiredGroupIsRefundedAndCreatesNothing(): void
    {
        $campaign = $this->campaign(moq: 10, deadline: '-1 hour');
        $user = $this->customer();

        $outcome = $this->service->confirm($this->paid($campaign, $user, quantity: 1, amount: 200, pi: 'pi_late'));

        self::assertSame(DepositOutcome::Refunded, $outcome);
        self::assertSame(0, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
        $this->em->refresh($campaign);
        self::assertSame(0, $campaign->getCurrentQuantity());
        self::assertArrayHasKey('rimborso_duplicato_pi_late', $this->stripe->refunds);
    }

    public function testPaymentOnAClosedGroupIsRefunded(): void
    {
        $campaign = $this->campaign(moq: 10);
        $campaign->setStatus(CampaignStatus::Annullata);
        $this->em->flush();

        $outcome = $this->service->confirm($this->paid($campaign, $this->customer(), quantity: 1, amount: 200, pi: 'pi_closed'));

        self::assertSame(DepositOutcome::Refunded, $outcome);
    }

    public function testFailedRefundOfAnExtraPaymentRaisesSoTheEventCanBeRetried(): void
    {
        $campaign = $this->campaign(moq: 10, deadline: '-1 hour');
        $this->stripe->failRefunds = true;
        $paid = $this->paid($campaign, $this->customer(), quantity: 1, amount: 200, pi: 'pi_retry');

        try {
            $this->service->confirm($paid);
            self::fail('Il rimborso fallito doveva interrompere la conferma.');
        } catch (StripeGatewayException) {
            // atteso
        }

        // Nulla è stato registrato: al secondo tentativo il pagamento viene rimborsato.
        $this->stripe->failRefunds = false;
        self::assertSame(DepositOutcome::Refunded, $this->service->confirm($paid));
        self::assertArrayHasKey('rimborso_duplicato_pi_retry', $this->stripe->refunds);
    }

    public function testReachingTheMinimumMovesTheGroupToSuccessfulAndConfirmsReservations(): void
    {
        $campaign = $this->campaign(moq: 5);
        $first = $this->customer();
        $second = $this->customer();
        $this->service->confirm($this->paid($campaign, $first, quantity: 3, amount: 600, pi: 'pi_a'));
        self::assertSame(ParticipationStatus::Prenotata, $this->participation($campaign, $first)->getStatus());

        $this->service->confirm($this->paid($campaign, $second, quantity: 2, amount: 400, pi: 'pi_b'));

        $this->em->refresh($campaign);
        self::assertSame(CampaignStatus::Riuscita, $campaign->getStatus());
        self::assertNotNull($campaign->getStatusUpdatedAt());
        $this->em->refresh($this->participation($campaign, $first));
        self::assertSame(ParticipationStatus::Confermata, $this->participation($campaign, $first)->getStatus());
        self::assertSame(ParticipationStatus::Confermata, $this->participation($campaign, $second)->getStatus());
    }

    public function testMemberJoiningAnAlreadySuccessfulGroupIsConfirmedFromTheStart(): void
    {
        $campaign = $this->campaign(moq: 2);
        $this->service->confirm($this->paid($campaign, $this->customer(), quantity: 2, amount: 400, pi: 'pi_x'));
        $late = $this->customer();

        $this->service->confirm($this->paid($campaign, $late, quantity: 1, amount: 200, pi: 'pi_y'));

        self::assertSame(ParticipationStatus::Confermata, $this->participation($campaign, $late)->getStatus());
        $this->em->refresh($campaign);
        self::assertSame(3, $campaign->getCurrentQuantity());
        self::assertSame(CampaignStatus::Riuscita, $campaign->getStatus());
    }

    public function testMissingOrInvalidMetadataIsIgnored(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();

        $noDelivery = new PaidDeposit('pi_nometa', 200, 'pm_1', 'succeeded', ['colletta_id' => $campaign->getId()->toRfc4122(), 'utente_id' => $user->getId()->toRfc4122(), 'quantita' => '1']);
        $badIds = new PaidDeposit('pi_badid', 200, 'pm_1', 'succeeded', ['colletta_id' => 'x', 'utente_id' => 'y', 'delivery_type' => 'ritiro_sede']);
        $unknown = new PaidDeposit('pi_unknown', 200, 'pm_1', 'succeeded', ['colletta_id' => '018f0000-0000-7000-8000-000000000000', 'utente_id' => $user->getId()->toRfc4122(), 'delivery_type' => 'ritiro_sede']);

        self::assertSame(DepositOutcome::Ignored, $this->service->confirm($noDelivery));
        self::assertSame(DepositOutcome::Ignored, $this->service->confirm($badIds));
        self::assertSame(DepositOutcome::Ignored, $this->service->confirm($unknown));
        self::assertSame(0, $this->em->getRepository(Participation::class)->count([]));
        self::assertSame([], $this->stripe->refunds);
    }

    private function campaign(int $moq, string $deadline = '+14 days'): Campaign
    {
        $product = (new Product($this->supplier, 'Prodotto '.++$this->counter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, $moq, new \DateTimeImmutable($deadline), 1200, 1000);
        $campaign->setPickupPoint($this->pickupPoint);
        $campaign->addPriceTier(new PriceTier($campaign, $moq, 1000));
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function customer(): User
    {
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@confirm-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, string> $delivery sostituisce i dati di consegna dei metadati
     */
    private function paid(Campaign $campaign, User $user, int $quantity, int $amount, string $pi, array $delivery = []): PaidDeposit
    {
        return new PaidDeposit($pi, $amount, 'pm_test', 'succeeded', $delivery + [
            'tipo' => 'acconto',
            'colletta_id' => $campaign->getId()->toRfc4122(),
            'quantita' => (string) $quantity,
            'utente_id' => $user->getId()->toRfc4122(),
            'delivery_type' => 'ritiro_sede',
            'id_sede' => $this->pickupPoint->getId()->toRfc4122(),
            'indirizzo' => '',
        ]);
    }

    private function participation(Campaign $campaign, User $user): Participation
    {
        return $this->em->getRepository(Participation::class)->findOneBy(['campaign' => $campaign, 'user' => $user])
            ?? self::fail('Adesione non trovata.');
    }
}
