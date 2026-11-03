<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use App\Identity\Enum\UserRole;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-10: creazione, modifica ed eliminazione dei gruppi da parte dell'admin.
 */
final class CampaignAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Tutto quello che il test scrive viene annullato al termine.
        $this->em->getConnection()->beginTransaction();

        $this->supplier = new Supplier('Fornitore admin test');
        $this->pickupPoint = new PickupPoint('Sede admin test', 'Via Prova 1', 'Perugia');
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

    /* ------------------------------ Permessi ------------------------------ */

    public function testGuestCannotCreateAndGets401(): void
    {
        $this->send('POST', '/api/campaigns', $this->newProductPayload());

        self::assertResponseStatusCodeSame(401);
    }

    public function testCustomerCannotCreateAndGets403(): void
    {
        $this->client->loginUser($this->user(UserRole::Cliente));

        $this->send('POST', '/api/campaigns', $this->newProductPayload());

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->json()['ok']);
    }

    public function testCustomerCannotUpdateOrDelete(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->user(UserRole::Cliente));

        $this->send('PATCH', $this->url($campaign), ['nome' => 'Hack']);
        self::assertResponseStatusCodeSame(403);

        $this->send('DELETE', $this->url($campaign));
        self::assertResponseStatusCodeSame(403);
    }

    /* ------------------------------ Creazione ------------------------------ */

    public function testAdminCreatesGroupWithNewProductAndTiers(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->client->loginUser($admin);

        $this->send('POST', '/api/campaigns', $this->newProductPayload() + [
            'scaglioni' => [['soglia' => 20, 'prezzo_cents' => 800], ['soglia' => 10, 'prezzo_cents' => 900]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $dati = $this->json()['dati'];
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, \Symfony\Component\Uid\Uuid::fromString($dati['id']));
        self::assertNotNull($campaign);
        // Il minimo è la soglia del primo scaglione.
        self::assertSame(10, $campaign->getMoq());
        self::assertSame(1200, $campaign->getBasePriceCents());
        self::assertSame(1000, $campaign->getCurrentPriceCents());
        self::assertSame('10.00', $campaign->getCommissionRate());
        self::assertSame(CampaignStatus::InCorso, $campaign->getStatus());
        self::assertSame($admin->getId()->toRfc4122(), $campaign->getOpenedBy()?->getId()->toRfc4122());
        self::assertSame('Zaino nuovo', $campaign->getProduct()->getName());
        $thresholds = array_map(static fn (PriceTier $t): array => [$t->getThreshold(), $t->getPriceCents()], $campaign->getPriceTiers()->toArray());
        sort($thresholds);
        self::assertSame([[10, 900], [20, 800]], $thresholds);
    }

    public function testNewGroupIsVisibleInThePublicCatalog(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));
        $this->send('POST', '/api/campaigns', $this->newProductPayload());
        $id = $this->json()['dati']['id'];

        $this->send('GET', '/api/campaigns/'.$id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Zaino nuovo', $this->json()['dati']['prodotto']);
    }

    public function testAdminCreatesGroupOnExistingProduct(): void
    {
        $product = new Product($this->supplier, 'Prodotto esistente', 1100);
        $product->setBasePriceCents(1500)->setMinQuantity(7);
        $this->em->persist($product);
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', [
            'prodotto_id' => $product->getId()->toRfc4122(),
            'scadenza' => $this->future('+10 days'),
            'sede_id' => $this->pickupPoint->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $campaign = $this->reload($this->json()['dati']['id']);
        self::assertSame(7, $campaign->getMoq());
        self::assertSame(1100, $campaign->getCurrentPriceCents());
        self::assertSame(1500, $campaign->getBasePriceCents());
        self::assertSame($product->getId()->toRfc4122(), $campaign->getProduct()->getId()->toRfc4122());
    }

    public function testCreationWithMinimumBelowOneIsRejectedWith400(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['quantita_minima' => 0] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('MOQ_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testCreationWithMoreThanTenTiersIsRejectedWith400(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));
        $tiers = [];
        for ($i = 1; $i <= 11; ++$i) {
            $tiers[] = ['soglia' => $i * 10, 'prezzo_cents' => 1100 - $i * 10];
        }

        $this->send('POST', '/api/campaigns', $this->newProductPayload() + ['scaglioni' => $tiers]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('SCAGLIONI_NON_VALIDI', $this->json()['errore']['codice']);
    }

    public function testExactlyTenTiersAreAccepted(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));
        $tiers = [];
        for ($i = 1; $i <= 10; ++$i) {
            $tiers[] = ['soglia' => $i * 10, 'prezzo_cents' => 1100 - $i * 10];
        }

        $this->send('POST', '/api/campaigns', $this->newProductPayload() + ['scaglioni' => $tiers]);

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param list<array<string, mixed>> $tiers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTiers')]
    public function testInvalidTiersAreRejected(array $tiers, string $expectedMessagePart): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', $this->newProductPayload() + ['scaglioni' => $tiers]);

        self::assertResponseStatusCodeSame(400);
        $error = $this->json()['errore'];
        self::assertSame('SCAGLIONI_NON_VALIDI', $error['codice']);
        self::assertStringContainsString($expectedMessagePart, $error['messaggio']);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function invalidTiers(): iterable
    {
        yield 'prezzi che non scendono' => [[['soglia' => 10, 'prezzo_cents' => 900], ['soglia' => 20, 'prezzo_cents' => 900]], 'diminuire'];
        yield 'prezzi che salgono' => [[['soglia' => 10, 'prezzo_cents' => 800], ['soglia' => 20, 'prezzo_cents' => 900]], 'diminuire'];
        yield 'soglie duplicate' => [[['soglia' => 10, 'prezzo_cents' => 900], ['soglia' => 10, 'prezzo_cents' => 800]], 'duplicate'];
        yield 'soglia zero' => [[['soglia' => 0, 'prezzo_cents' => 900]], 'soglia'];
        yield 'prezzo zero' => [[['soglia' => 10, 'prezzo_cents' => 0]], 'prezzo'];
        yield 'prezzo con decimali' => [[['soglia' => 10, 'prezzo_cents' => 9.5]], 'prezzo'];
    }

    public function testListinoNotAboveFirstTierPriceIsRejectedWith422(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['prezzo_base_cents' => 900] + $this->newProductPayload() + [
            'scaglioni' => [['soglia' => 10, 'prezzo_cents' => 900]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $error = $this->json()['errore'];
        self::assertSame('DATI_NON_VALIDI', $error['codice']);
        self::assertArrayHasKey('prezzo_base', $error['campi']);
    }

    public function testPastDeadlineIsRejected(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['scadenza' => $this->future('-1 day')] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('DATA_NEL_PASSATO', $this->json()['errore']['codice']);
    }

    public function testInvalidDeadlineFormatIsRejected(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['scadenza' => 'domani forse'] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('DATA_NON_VALIDA', $this->json()['errore']['codice']);
    }

    public function testPricesMustBeIntegerCents(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['prezzo_corrente_cents' => 9.99] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('PREZZO_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testCommissionOutOfRangeIsRejected(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['percentuale_commissione' => 101] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('COMMISSIONE_NON_VALIDA', $this->json()['errore']['codice']);
    }

    public function testUnknownSupplierReturns404AndCreatesNothing(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));
        $before = $this->em->getRepository(Campaign::class)->count([]);

        $this->send('POST', '/api/campaigns', ['id_fornitore' => '018f0000-0000-7000-8000-000000000000'] + $this->newProductPayload());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('FORNITORE_INESISTENTE', $this->json()['errore']['codice']);
        self::assertSame($before, $this->em->getRepository(Campaign::class)->count([]));
    }

    public function testUnknownProductReturns404(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns', ['prodotto_id' => '018f0000-0000-7000-8000-000000000000', 'scadenza' => $this->future('+5 days')]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('PRODOTTO_INESISTENTE', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Modifica ------------------------------ */

    public function testOpenGroupWithoutParticipationsCanChangeMinimumPriceAndTiers(): void
    {
        $campaign = $this->campaign(tiers: [10 => 900, 20 => 800]);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), [
            'prezzo_corrente_cents' => 950,
            'prezzo_base_cents' => 1300,
            'percentuale_commissione' => 12.5,
            // La soglia 10 viene riusata: i vecchi scaglioni vanno tolti prima dei nuovi.
            'scaglioni' => [['soglia' => 5, 'prezzo_cents' => 950], ['soglia' => 10, 'prezzo_cents' => 850]],
        ]);

        self::assertResponseStatusCodeSame(200);
        $campaign = $this->reload($campaign->getId()->toRfc4122());
        self::assertSame(5, $campaign->getMoq());
        self::assertSame(950, $campaign->getCurrentPriceCents());
        self::assertSame(1300, $campaign->getBasePriceCents());
        self::assertSame('12.50', $campaign->getCommissionRate());
        $thresholds = array_map(static fn (PriceTier $t): array => [$t->getThreshold(), $t->getPriceCents()], $campaign->getPriceTiers()->toArray());
        sort($thresholds);
        self::assertSame([[5, 950], [10, 850]], $thresholds);
    }

    public function testChangingMinimumWithExistingParticipationsReturns409(): void
    {
        $campaign = $this->campaign(moq: 10);
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['quantita_minima' => 5]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('AZIONE_NON_CONSENTITA', $this->json()['errore']['codice']);
        self::assertSame(10, $this->reload($campaign->getId()->toRfc4122())->getMoq());
    }

    public function testChangingPriceWithExistingParticipationsReturns409(): void
    {
        $campaign = $this->campaign();
        $this->participate($campaign, ParticipationStatus::Pagata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['prezzo_corrente_cents' => 500]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1000, $this->reload($campaign->getId()->toRfc4122())->getCurrentPriceCents());
    }

    public function testChangingTiersOrCommissionWithExistingParticipationsReturns409(): void
    {
        $campaign = $this->campaign(tiers: [10 => 900]);
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['scaglioni' => [['soglia' => 10, 'prezzo_cents' => 800]]]);
        self::assertResponseStatusCodeSame(409);

        $this->send('PATCH', $this->url($campaign), ['percentuale_commissione' => 20]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testResendingUnchangedValuesWithParticipationsIsNotAnEdit(): void
    {
        $campaign = $this->campaign(moq: 10, tiers: [10 => 900]);
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), [
            'quantita_minima' => 10,
            'prezzo_corrente_cents' => 1000,
            'prezzo_base_cents' => 1200,
            'percentuale_commissione' => 10,
            'scaglioni' => [['soglia' => 10, 'prezzo_cents' => 900]],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['dati']['modificati']);
    }

    public function testTextsAndDeadlineExtensionAreAllowedWithParticipations(): void
    {
        $campaign = $this->campaign();
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));
        $newDeadline = $this->future('+40 days');

        $this->send('PATCH', $this->url($campaign), ['nome' => 'Nome nuovo', 'descrizione' => 'Testo nuovo', 'data_limite' => $newDeadline]);

        self::assertResponseStatusCodeSame(200);
        self::assertEqualsCanonicalizing(['nome', 'descrizione', 'data_limite'], $this->json()['dati']['modificati']);
        $campaign = $this->reload($campaign->getId()->toRfc4122());
        self::assertSame('Nome nuovo', $campaign->getProduct()->getName());
        self::assertSame('Testo nuovo', $campaign->getProduct()->getDescription());
        self::assertSame((new \DateTimeImmutable($newDeadline))->format('Y-m-d H:i'), $campaign->getDeadline()->format('Y-m-d H:i'));
    }

    public function testDeadlineCannotBeShortenedWithParticipations(): void
    {
        $campaign = $this->campaign(deadline: '+20 days');
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['data_limite' => $this->future('+5 days')]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('SCADENZA_NON_ACCORCIABILE', $this->json()['errore']['codice']);
    }

    public function testDeadlineCanBeShortenedWithoutParticipations(): void
    {
        $campaign = $this->campaign(deadline: '+20 days');
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['data_limite' => $this->future('+5 days')]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testPastDeadlineOnUpdateIsRejected(): void
    {
        $campaign = $this->campaign();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['data_limite' => $this->future('-1 day')]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('SCADENZA_NON_VALIDA', $this->json()['errore']['codice']);
    }

    public function testGroupWithOrderAlreadySentCannotBeEdited(): void
    {
        $campaign = $this->campaign();
        $campaign->setStatus(CampaignStatus::OrdineFornitore);
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['nome' => 'Nuovo nome']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CAMPAGNA_NON_MODIFICABILE', $this->json()['errore']['codice']);
    }

    public function testExpiredGroupBelowMinimumCannotBeEdited(): void
    {
        $campaign = $this->campaign(moq: 10, deadline: '-1 day');
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', $this->url($campaign), ['nome' => 'Nuovo nome']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('AZIONE_NON_CONSENTITA', $this->json()['errore']['codice']);
    }

    public function testUpdateOfUnknownGroupReturns404(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('PATCH', '/api/campaigns/018f0000-0000-7000-8000-000000000000', ['nome' => 'X']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('CAMPAGNA_INESISTENTE', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Eliminazione ------------------------------ */

    public function testGroupWithoutPaymentsCanBeDeleted(): void
    {
        $campaign = $this->campaign(tiers: [10 => 900]);
        $id = $campaign->getId()->toRfc4122();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('DELETE', $this->url($campaign));

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['dati']['eliminata']);
        $this->em->clear();
        self::assertNull($this->em->find(Campaign::class, \Symfony\Component\Uid\Uuid::fromString($id)));
        $this->send('GET', '/api/campaigns/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testGroupWithPaymentsCannotBeDeleted(): void
    {
        $campaign = $this->campaign();
        // Adesione già rimborsata: non è "viva", ma il pagamento resta registrato.
        $participation = $this->participate($campaign, ParticipationStatus::Rimborsata);
        $this->em->persist(new Payment($participation, PaymentType::Acconto, 200));
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('DELETE', $this->url($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('HA_PAGAMENTI', $this->json()['errore']['codice']);
        $this->reload($campaign->getId()->toRfc4122());   // ancora presente
    }

    public function testGroupWithLiveParticipationsCannotBeDeleted(): void
    {
        $campaign = $this->campaign();
        $this->participate($campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('DELETE', $this->url($campaign));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('AZIONE_NON_CONSENTITA', $this->json()['errore']['codice']);
    }

    public function testDeleteOfUnknownGroupReturns404(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('DELETE', '/api/campaigns/018f0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    /* ------------------------------ Supporto ------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function newProductPayload(): array
    {
        return [
            'nome' => 'Zaino nuovo',
            'id_fornitore' => $this->supplier->getId()->toRfc4122(),
            'prezzo_corrente_cents' => 1000,
            'prezzo_base_cents' => 1200,
            'quantita_minima' => 10,
            'scadenza' => $this->future('+14 days'),
            'sede_id' => $this->pickupPoint->getId()->toRfc4122(),
        ];
    }

    private function future(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format(\DATE_ATOM);
    }

    private function user(UserRole $role): User
    {
        $user = new User('Prova', 'u'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@admin-test.example');
        $user->setRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<int, int> $tiers soglia → prezzo in centesimi
     */
    private function campaign(int $moq = 10, string $deadline = '+14 days', array $tiers = []): Campaign
    {
        $product = (new Product($this->supplier, 'Prodotto '.++$this->counter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, $moq, new \DateTimeImmutable($deadline), 1200, 1000);
        $campaign->setPickupPoint($this->pickupPoint);
        foreach ($tiers as $threshold => $price) {
            $campaign->addPriceTier(new PriceTier($campaign, $threshold, $price));
        }
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function participate(Campaign $campaign, ParticipationStatus $status): Participation
    {
        $customer = $this->user(UserRole::Cliente);
        $participation = (new Participation($campaign, $customer, 1))->setStatus($status);
        $this->em->persist($participation);
        $this->em->flush();

        return $participation;
    }

    private function url(Campaign $campaign): string
    {
        return '/api/campaigns/'.$campaign->getId()->toRfc4122();
    }

    private function reload(string $id): Campaign
    {
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, \Symfony\Component\Uid\Uuid::fromString($id));

        return $campaign ?? self::fail('Gruppo '.$id.' non trovato.');
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
