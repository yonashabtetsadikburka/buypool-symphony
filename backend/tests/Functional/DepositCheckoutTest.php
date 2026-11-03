<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Supplier\Entity\Supplier;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-04: "Unisciti al gruppo" — pagamento dell'acconto su Stripe (finto nei test).
 */
final class DepositCheckoutTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeStripeGateway $stripe;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->stripe = self::getContainer()->get(FakeStripeGateway::class);
        $this->em->getConnection()->beginTransaction();

        $this->supplier = new Supplier('Fornitore acconto test');
        $this->pickupPoint = new PickupPoint('Sede acconto test', 'Via Prova 1', 'Perugia');
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

    public function testDirectJoinWithoutPaymentReturns410ForGuestsAndCustomers(): void
    {
        $campaign = $this->campaign();

        $this->send('POST', '/api/campaigns/'.$campaign->getId()->toRfc4122().'/join');
        self::assertResponseStatusCodeSame(410);
        self::assertSame('FLUSSO_DISABILITATO', $this->json()['errore']['codice']);

        $this->client->loginUser($this->customer());
        $this->send('POST', '/api/campaigns/'.$campaign->getId()->toRfc4122().'/join');
        self::assertResponseStatusCodeSame(410);
    }

    public function testCheckoutRequiresLogin(): void
    {
        $this->send('POST', '/api/payments/deposit-checkout', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDepositIsQuantityTimesFirstTierPriceTimesPercentage(): void
    {
        // 3 unità × 900 (primo scaglione, non il prezzo corrente 1000) × 20% = 540
        $campaign = $this->campaign(tiers: [10 => 900, 20 => 800]);
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 3));

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame(540, $dati['importo_cents']);
        self::assertStringStartsWith('https://checkout.stripe.test/', $dati['checkout_url']);
        $request = $this->stripe->checkoutRequests[0];
        self::assertSame(540, $request->amountCents);
        self::assertSame('acconto', $request->metadata['tipo']);
        self::assertSame($campaign->getId()->toRfc4122(), $request->metadata['colletta_id']);
        self::assertSame($user->getId()->toRfc4122(), $request->metadata['utente_id']);
        self::assertSame('3', $request->metadata['quantita']);
        self::assertSame('ritiro_sede', $request->metadata['delivery_type']);
        self::assertSame($this->pickupPoint->getId()->toRfc4122(), $request->metadata['id_sede']);
    }

    public function testWithoutTiersTheDepositUsesTheCurrentPrice(): void
    {
        $campaign = $this->campaign();   // prezzo corrente 1000
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 1));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(200, $this->json()['dati']['importo_cents']);
    }

    public function testCheckoutDoesNotCreateAnyParticipationOrChangeTheQuantity(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->em->getRepository(Participation::class)->count([]));
        $this->em->refresh($campaign);
        self::assertSame(0, $campaign->getCurrentQuantity());
    }

    public function testStripeCustomerIsCreatedOnceAndReused(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));
        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 2));

        self::assertCount(1, $this->stripe->customers);
        self::assertNotNull($user->getStripeCustomerId());
    }

    public function testSameChoiceReusesTheOpenSessionInsteadOfCreatingAnother(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));
        $first = $this->json()['dati']['session_id'];
        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertSame($first, $this->json()['dati']['session_id']);
        self::assertCount(1, $this->stripe->checkoutRequests);
        self::assertSame([], $this->stripe->expired);
    }

    public function testChangedChoiceExpiresTheOldSessionAndCreatesANewOne(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 1));
        $first = $this->json()['dati']['session_id'];
        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 2));

        self::assertNotSame($first, $this->json()['dati']['session_id']);
        self::assertSame([$first], $this->stripe->expired);
    }

    public function testHomeDeliveryNeedsAnAddressAndRecordsIt(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', ['delivery' => ['tipo' => 'consegna_domicilio', 'via' => 'Via Roma 1']] + $this->payload($campaign));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INDIRIZZO_MANCANTE', $this->json()['errore']['codice']);

        $this->send('POST', '/api/payments/deposit-checkout', ['delivery' => ['tipo' => 'consegna_domicilio', 'via' => 'Via Roma 1', 'cap' => '06100', 'citta' => 'Perugia', 'provincia' => 'pg']] + $this->payload($campaign));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Via Roma 1, 06100 Perugia (PG)', $this->stripe->checkoutRequests[0]->metadata['indirizzo']);
        self::assertSame('', $this->stripe->checkoutRequests[0]->metadata['id_sede']);
    }

    public function testDeliveryChoiceIsRequiredAndValidated(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', ['colletta_id' => $campaign->getId()->toRfc4122(), 'quantita' => 1]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('SCELTA_MANCANTE', $this->json()['errore']['codice']);

        $this->send('POST', '/api/payments/deposit-checkout', ['delivery' => ['tipo' => 'drone']] + $this->payload($campaign));
        self::assertSame('SCELTA_NON_VALIDA', $this->json()['errore']['codice']);

        $this->send('POST', '/api/payments/deposit-checkout', ['delivery' => ['tipo' => 'ritiro_sede', 'id_sede' => '018f0000-0000-7000-8000-000000000000']] + $this->payload($campaign));
        self::assertSame('SEDE_NON_VALIDA', $this->json()['errore']['codice']);
    }

    public function testDepositBelowFiftyCentsIsRejectedWith422(): void
    {
        // 1 unità × 100 × 20% = 20 centesimi
        $campaign = $this->campaign(currentPrice: 100);
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ACCONTO_MINIMO', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->checkoutRequests);
    }

    public function testDepositOfExactlyFiftyCentsIsAccepted(): void
    {
        // 1 × 250 × 20% = 50
        $campaign = $this->campaign(currentPrice: 250);
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(50, $this->json()['dati']['importo_cents']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedStatuses')]
    public function testGroupNotOpenRefusesTheCheckout(CampaignStatus $status): void
    {
        $campaign = $this->campaign();
        $campaign->setStatus($status);
        $this->em->flush();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CAMPAGNA_NON_APERTA', $this->json()['errore']['codice']);
    }

    /** @return iterable<string, array{CampaignStatus}> */
    public static function closedStatuses(): iterable
    {
        yield 'annullato' => [CampaignStatus::Annullata];
        yield 'fallito' => [CampaignStatus::Fallita];
        yield 'ordine pronto' => [CampaignStatus::OrdinePronto];
        yield 'consegnato' => [CampaignStatus::Consegnata];
    }

    public function testSuccessfulGroupStillAcceptsMembersBeforeTheDeadline(): void
    {
        $campaign = $this->campaign();
        $campaign->setStatus(CampaignStatus::Riuscita);
        $this->em->flush();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(200);
    }

    public function testExpiredGroupRefusesTheCheckout(): void
    {
        $campaign = $this->campaign(deadline: '-1 hour');
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CAMPAGNA_SCADUTA', $this->json()['errore']['codice']);
    }

    public function testCustomerAlreadyInTheGroupCannotPayAnotherDeposit(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $this->em->persist(new Participation($campaign, $user, 1));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_PARTECIPI', $this->json()['errore']['codice']);
    }

    public function testRefundedMemberAlsoCannotRejoinBecauseTheLegacyBlocksAnyExistingRow(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $this->em->persist((new Participation($campaign, $user, 1))->setStatus(ParticipationStatus::Rimborsata));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_PARTECIPI', $this->json()['errore']['codice']);
    }

    public function testUnknownGroupReturns404AndMalformedIdReturns400(): void
    {
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', ['colletta_id' => '018f0000-0000-7000-8000-000000000000', 'delivery' => ['tipo' => 'consegna_domicilio']]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('CAMPAGNA_INESISTENTE', $this->json()['errore']['codice']);

        $this->send('POST', '/api/payments/deposit-checkout', ['colletta_id' => 'abc']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testQuantityMustBeAnIntegerAndIsKeptBetweenOneAndNinetyNine(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', ['quantita' => 2.5] + $this->payload($campaign));
        self::assertResponseStatusCodeSame(400);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 500));
        self::assertSame('99', $this->stripe->checkoutRequests[0]->metadata['quantita']);

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign, quantity: 0));
        self::assertSame('1', end($this->stripe->checkoutRequests)->metadata['quantita']);
    }

    public function testStripeFailureBecomesAnApiError(): void
    {
        $campaign = $this->campaign();
        $this->stripe->failCheckout = true;
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/payments/deposit-checkout', $this->payload($campaign));

        self::assertResponseStatusCodeSame(500);
        self::assertSame('STRIPE_ERRORE', $this->json()['errore']['codice']);
    }

    /**
     * @param array<int, int> $tiers soglia → prezzo in centesimi
     */
    private function campaign(array $tiers = [], int $currentPrice = 1000, string $deadline = '+14 days'): Campaign
    {
        $product = (new Product($this->supplier, 'Prodotto '.++$this->counter, $currentPrice))->setBasePriceCents($currentPrice + 200);
        $campaign = new Campaign($product, [] !== $tiers ? array_key_first($tiers) : 10, new \DateTimeImmutable($deadline), $currentPrice + 200, $currentPrice);
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
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@deposit-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Campaign $campaign, int $quantity = 1): array
    {
        return [
            'colletta_id' => $campaign->getId()->toRfc4122(),
            'quantita' => $quantity,
            'delivery' => ['tipo' => 'ritiro_sede', 'id_sede' => $this->pickupPoint->getId()->toRfc4122()],
        ];
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
