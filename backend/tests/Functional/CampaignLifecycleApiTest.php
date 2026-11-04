<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Identity\Entity\User;
use App\Identity\Enum\UserRole;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Supplier\Entity\SupplierOrder;
use App\Tests\Support\FakeStripeGateway;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * US-11: endpoint admin di chiusura, annullamento e invio al fornitore.
 */
final class CampaignLifecycleApiTest extends WebTestCase
{
    use GroupFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->stripe = self::getContainer()->get(FakeStripeGateway::class);
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

    /**
     * @dataProvider endpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function testOnlyAnAdminCanUseTheLifecycleEndpoints(string $action): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $url = '/api/campaigns/'.$campaign->getId()->toRfc4122().'/'.$action;

        $this->send('POST', $url, []);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->user(UserRole::Cliente));
        $this->send('POST', $url, []);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        yield 'chiusura' => ['close'];
        yield 'annullamento' => ['cancel'];
        yield 'invio al fornitore' => ['send-to-supplier'];
    }

    /* ------------------------------ Chiusura ------------------------------ */

    public function testClosingBeforeTheDeadlineIsRefused(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '+2 days');
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'close'), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CHIUSURA_ANTICIPATA', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->charges);
    }

    public function testClosingAnExpiredGroupChargesTheBalancesAndRepeatingReturns409(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $this->member($this->em, $campaign, ParticipationStatus::Confermata, home: true);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'close'), []);

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame(2, $dati['pagate']);
        self::assertTrue($dati['chiusa']);
        self::assertSame(CampaignStatus::OrdinePronto, $this->reload($campaign)->getStatus());

        // Seconda chiusura: 409 e nessun nuovo addebito.
        $this->send('POST', $this->url($campaign, 'close'), []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_CHIUSA', $this->json()['errore']['codice']);
        self::assertCount(2, $this->stripe->charges);
    }

    public function testClosingAGroupBelowTheMinimumRefundsTheDeposits(): void
    {
        $campaign = $this->group($this->em, quantity: 6);
        $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'close'), []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['dati']['rimborsate']);
        self::assertSame(CampaignStatus::Fallita, $this->reload($campaign)->getStatus());
    }

    public function testClosingAnUnknownGroupReturns404(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/campaigns/018f0000-0000-7000-8000-000000000000/close', []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('CAMPAGNA_INESISTENTE', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Annullamento ------------------------------ */

    public function testCancellationNeedsAReasonAndTheTypedConfirmation(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'cancel'), ['conferma' => 'ANNULLA']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('MOTIVO_OBBLIGATORIO', $this->json()['errore']['codice']);

        $this->send('POST', $this->url($campaign, 'cancel'), ['motivo' => str_repeat('x', 256), 'conferma' => 'ANNULLA']);
        self::assertSame('MOTIVO_TROPPO_LUNGO', $this->json()['errore']['codice']);

        $this->send('POST', $this->url($campaign, 'cancel'), ['motivo' => 'Fornitore non disponibile']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('CONFERMA_MANCANTE', $this->json()['errore']['codice']);
        self::assertSame(CampaignStatus::InCorso, $this->reload($campaign)->getStatus());
    }

    public function testCancellationRefundsEveryoneAndStoresTheReason(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'cancel'), ['motivo' => 'Fornitore non disponibile', 'conferma' => 'ANNULLA']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['rimborsate' => 1, 'falliti' => 0, 'completo' => true], $this->json()['dati']);
        $campaign = $this->reload($campaign);
        self::assertSame(CampaignStatus::Annullata, $campaign->getStatus());
        self::assertSame('Fornitore non disponibile', $campaign->getCancellationReason());
        self::assertSame(ParticipationStatus::Rimborsata, $this->reloadParticipation($member)->getStatus());
    }

    public function testAGroupWithAnOrderAtTheSupplierCannotBeCancelled(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'cancel'), ['motivo' => 'Fornitore non disponibile', 'conferma' => 'ANNULLA']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('AZIONE_NON_CONSENTITA', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Invio al fornitore ------------------------------ */

    public function testSendingTheOrderCreatesItOnceAndRepeatingReturns409(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdinePronto);
        $campaign->setFinalPriceCents(800);
        $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->member($this->em, $campaign, ParticipationStatus::Rimborsata);
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'send-to-supplier'), []);

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame(22, $dati['totale_unita']);
        self::assertSame(22 * 800, $dati['importo_ordine_cents']);
        self::assertSame(CampaignStatus::OrdineFornitore, $this->reload($campaign)->getStatus());
        self::assertSame(1, $this->em->getRepository(SupplierOrder::class)->count(['campaign' => $campaign]));

        $this->send('POST', $this->url($campaign, 'send-to-supplier'), []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);
        self::assertSame(1, $this->em->getRepository(SupplierOrder::class)->count(['campaign' => $campaign]));
    }

    public function testTheOrderCannotBeSentWhileABalanceIsStillPending(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdinePronto);
        $campaign->setFinalPriceCents(800);
        $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->member($this->em, $campaign, ParticipationStatus::AzioneRichiesta);
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'send-to-supplier'), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PAGAMENTI_INCOMPLETI', $this->json()['errore']['codice']);
        self::assertSame(CampaignStatus::OrdinePronto, $this->reload($campaign)->getStatus());
    }

    public function testTheOrderCannotBeSentBeforeTheGroupIsReady(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '+3 days');
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'send-to-supplier'), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testFromClosureToSupplierOrderEndToEnd(): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $this->member($this->em, $campaign, ParticipationStatus::Confermata, quantity: 3);
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', $this->url($campaign, 'close'), []);
        self::assertResponseStatusCodeSame(200);
        $this->send('POST', $this->url($campaign, 'send-to-supplier'), []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(CampaignStatus::OrdineFornitore, $this->reload($campaign)->getStatus());
    }

    /* ------------------------------ Supporto ------------------------------ */

    private function url(Campaign $campaign, string $action): string
    {
        return '/api/campaigns/'.$campaign->getId()->toRfc4122().'/'.$action;
    }

    private function user(UserRole $role): User
    {
        $user = new User('Prova', 'u'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@lifecycle-test.example');
        $user->setRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Dopo la seconda richiesta il kernel di test svuota l'EntityManager: si rilegge dal database. */
    private function reload(Campaign $campaign): Campaign
    {
        $id = $campaign->getId();
        $this->em->clear();

        return $this->em->find(Campaign::class, $id) ?? self::fail('Gruppo non trovato.');
    }

    private function reloadParticipation(Participation $participation): Participation
    {
        return $this->em->find(Participation::class, Uuid::fromString($participation->getId()->toRfc4122())) ?? self::fail('Adesione non trovata.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $method, string $uri, array $data): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
