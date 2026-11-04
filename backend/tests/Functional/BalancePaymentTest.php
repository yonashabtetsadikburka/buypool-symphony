<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Service\BalanceService;
use App\Payment\Gateway\CheckoutSessionStatus;
use App\Payment\Gateway\PaymentIntentData;
use App\Supplier\Entity\Supplier;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-07: calcolo del saldo sul server, pagamento del saldo e controllo dell'esito dopo il ritorno da Stripe.
 */
final class BalancePaymentTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeStripeGateway $stripe;
    private BalanceService $balances;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->stripe = $container->get(FakeStripeGateway::class);
        $this->balances = $container->get(BalanceService::class);
        $this->em->getConnection()->beginTransaction();

        $this->supplier = new Supplier('Fornitore saldo test');
        $this->pickupPoint = new PickupPoint('Sede saldo test', 'Via Prova 1', 'Perugia');
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

    /* ------------------------------ Formula del saldo ------------------------------ */

    public function testBalanceIsQuantityTimesReachedTierPlusCommissionPlusShippingMinusDeposit(): void
    {
        // 3 × 800 (scaglione raggiunto con 22 unità) = 2400; commissione 10% = 240; spedizione 490; acconto 540
        [, $participation] = $this->participation(quantity: 3, home: true);

        $b = $this->balances->breakdown($participation);

        self::assertSame(2400, $b->goodsCents);
        self::assertSame(240, $b->commissionCents);
        self::assertSame(490, $b->shippingCents);
        self::assertSame(3130, $b->totalCents);
        self::assertSame(540, $b->depositCents);
        self::assertSame(2590, $b->balanceCents);
    }

    public function testPickupHasNoShippingCost(): void
    {
        [, $participation] = $this->participation(quantity: 3, home: false);

        $b = $this->balances->breakdown($participation);

        self::assertSame(0, $b->shippingCents);
        self::assertSame(2100, $b->balanceCents);   // 2400 + 240 − 540
    }

    public function testPriceFixedAtClosureWinsOverTheCurrentTier(): void
    {
        [$campaign, $participation] = $this->participation(quantity: 3, home: false);
        $campaign->setFinalPriceCents(900);
        $this->em->flush();

        $b = $this->balances->breakdown($participation);

        self::assertSame(2700, $b->goodsCents);
        self::assertSame(270, $b->commissionCents);
        self::assertSame(2430, $b->balanceCents);   // 2700 + 270 − 540
    }

    public function testCommissionIsRoundedHalfUpToTheCent(): void
    {
        // 3 × 801 = 2403; 12,5% = 300,375 → 300
        [$campaign, $participation] = $this->participation(quantity: 3, home: false, tiers: [10 => 801]);
        $campaign->setCommissionRate('12.50');
        $this->em->flush();

        self::assertSame(300, $this->balances->breakdown($participation)->commissionCents);
    }

    /* ------------------------------ Pagamento del saldo ------------------------------ */

    public function testBalanceCheckoutRequiresLogin(): void
    {
        $this->send('POST', '/api/payments/balance-checkout', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBalanceCheckoutChargesTheServerComputedBalance(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: true);
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => $participation->getId()->toRfc4122(), 'importo_cents' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2590, $this->json()['dati']['importo_cents']);
        $request = $this->stripe->checkoutRequests[0];
        self::assertSame(2590, $request->amountCents);
        self::assertSame('saldo', $request->metadata['tipo']);
        self::assertSame($participation->getId()->toRfc4122(), $request->metadata['prenotazione_id']);
        self::assertSame($user->getId()->toRfc4122(), $request->metadata['utente_id']);
        self::assertSame(2590, $this->reload($participation)->getBalanceCents());
    }

    public function testBalanceBelowFiftyCentsIsChargedAsFiftyCents(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: false);
        $participation->setDepositCents(2400 + 240 - 20);   // saldo = 20 centesimi
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => $participation->getId()->toRfc4122()]);

        self::assertSame(50, $this->json()['dati']['importo_cents']);
    }

    public function testNothingToPayReturns409(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: false);
        $participation->setDepositCents(5000);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => $participation->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('NESSUN_SALDO', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->checkoutRequests);
    }

    public function testOnlyConfirmedParticipationsCanPayTheBalance(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: false);
        $participation->setStatus(ParticipationStatus::Prenotata);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => $participation->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testSomeoneElsesParticipationIsNotFound(): void
    {
        [, $participation] = $this->participation(quantity: 3, home: false);
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => $participation->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('PRENOTAZIONE_NON_TROVATA', $this->json()['errore']['codice']);
    }

    public function testBalanceCheckoutNeedsAValidParticipationId(): void
    {
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/balance-checkout', ['prenotazione_id' => 'abc']);

        self::assertResponseStatusCodeSame(400);
    }

    /* ------------------------------ Esito dopo il pagamento ------------------------------ */

    public function testPaidSessionWithoutWebhookIsRecordedByTheStatusCheck(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: true);
        $this->stripeSays('cs_paid', 'pi_paid', 'paid', $participation, $user, 2590);
        $this->client->loginUser($user);

        $this->send('GET', '/api/payments/status?session_id=cs_paid');

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame('paid', $dati['status']);
        self::assertSame($participation->getId()->toRfc4122(), $dati['prenotazione']);
        self::assertSame(2590, $dati['importo_cents']);
        self::assertSame(ParticipationStatus::Pagata, $this->reload($participation)->getStatus());

        // Un secondo controllo (o il webhook che arriva dopo) non registra nulla di nuovo.
        $this->send('GET', '/api/payments/status?session_id=cs_paid');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->stripe->refunds);
    }

    public function testUnpaidSessionChangesNothing(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: true);
        $this->stripeSays('cs_open', 'pi_open', 'unpaid', $participation, $user, 2590);
        $this->client->loginUser($user);

        $this->send('GET', '/api/payments/status?session_id=cs_open');

        self::assertSame('unpaid', $this->json()['dati']['status']);
        self::assertSame(ParticipationStatus::Confermata, $this->reload($participation)->getStatus());
    }

    public function testStatusCheckRecordsAPaidDepositAsAParticipation(): void
    {
        $campaign = $this->campaign([10 => 900]);
        $user = $this->customer();
        $metadata = [
            'tipo' => 'acconto', 'colletta_id' => $campaign->getId()->toRfc4122(), 'quantita' => '2', 'utente_id' => $user->getId()->toRfc4122(),
            'delivery_type' => 'ritiro_sede', 'id_sede' => $this->pickupPoint->getId()->toRfc4122(), 'indirizzo' => '',
        ];
        $this->stripe->sessionStatuses['cs_dep'] = new CheckoutSessionStatus('cs_dep', 'paid', 'pi_dep', 360, $metadata);
        $this->stripe->paymentIntents['pi_dep'] = new PaymentIntentData('pi_dep', 360, 'pm_1', 'succeeded', $metadata);
        $this->client->loginUser($user);

        $this->send('GET', '/api/payments/status?session_id=cs_dep');
        $this->send('GET', '/api/payments/status?session_id=cs_dep');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
    }

    public function testSessionOfAnotherUserIsRefused(): void
    {
        [, $participation, $user] = $this->participation(quantity: 3, home: true);
        $this->stripeSays('cs_other', 'pi_other', 'paid', $participation, $user, 2590);
        $this->client->loginUser($this->customer());

        $this->send('GET', '/api/payments/status?session_id=cs_other');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ParticipationStatus::Confermata, $this->reload($participation)->getStatus());
    }

    public function testStatusCheckNeedsASessionIdAndAKnownSession(): void
    {
        $this->client->loginUser($this->customer());

        $this->send('GET', '/api/payments/status');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('SESSION_ID_MANCANTE', $this->json()['errore']['codice']);

        $this->send('GET', '/api/payments/status?session_id=cs_nope');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('STRIPE_ERRORE', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Supporto ------------------------------ */

    private function stripeSays(string $sessionId, string $intentId, string $paymentStatus, Participation $participation, User $user, int $amount): void
    {
        $metadata = [
            'tipo' => 'saldo',
            'prenotazione_id' => $participation->getId()->toRfc4122(),
            'colletta_id' => $participation->getCampaign()->getId()->toRfc4122(),
            'utente_id' => $user->getId()->toRfc4122(),
        ];
        $this->stripe->sessionStatuses[$sessionId] = new CheckoutSessionStatus($sessionId, $paymentStatus, $intentId, $amount, $metadata);
        $this->stripe->paymentIntents[$intentId] = new PaymentIntentData($intentId, $amount, 'pm_1', 'succeeded', $metadata);
    }

    /**
     * Gruppo con scaglioni 10 → 900 e 20 → 800 (o quelli passati), 22 unità, commissione 10%,
     * e un'adesione "confermata" con acconto di 540.
     *
     * @param array<int, int>|null $tiers
     *
     * @return array{Campaign, Participation, User}
     */
    private function participation(int $quantity, bool $home, ?array $tiers = null): array
    {
        $campaign = $this->campaign($tiers ?? [10 => 900, 20 => 800]);
        $campaign->setCurrentQuantity(22);
        $user = $this->customer();
        $participation = (new Participation($campaign, $user, $quantity))->setDepositCents(540)->setStatus(ParticipationStatus::Confermata);
        $delivery = new Delivery($participation, $home ? DeliveryMode::ConsegnaDomicilio : DeliveryMode::RitiroSede);
        $delivery->setDeliveryCostCents($home ? 490 : 0);
        $this->em->persist($participation);
        $this->em->persist($delivery);
        $this->em->flush();

        return [$campaign, $participation, $user];
    }

    /**
     * @param array<int, int> $tiers
     */
    private function campaign(array $tiers): Campaign
    {
        $product = (new Product($this->supplier, 'Prodotto '.++$this->counter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, (int) array_key_first($tiers), new \DateTimeImmutable('+14 days'), 1200, 1000);
        $campaign->setPickupPoint($this->pickupPoint);
        foreach ($tiers as $threshold => $price) {
            $campaign->addPriceTier(new PriceTier($campaign, $threshold, $price));
        }
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function customer(): User
    {
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@balance-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Dopo la seconda richiesta il kernel di test svuota l'EntityManager: si rilegge dal database.
     */
    private function reload(Participation $participation): Participation
    {
        $id = $participation->getId();
        $this->em->clear();

        return $this->em->find(Participation::class, $id) ?? self::fail('Adesione non trovata.');
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function send(string $method, string $uri, ?array $data = null): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: null === $data ? null : json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
