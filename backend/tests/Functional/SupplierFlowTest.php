<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Enum\CampaignStatus;
use App\Identity\Entity\User;
use App\Identity\Enum\UserRole;
use App\Participation\Enum\ParticipationStatus;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierInvite;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Service\SupplierInviteService;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-12: invito e attivazione del fornitore, ordini visti solo dal proprietario e avanzamento
 * Da confermare → In preparazione → Spedito.
 */
final class SupplierFlowTest extends WebTestCase
{
    use GroupFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    /* ------------------------------ Invito ------------------------------ */

    public function testOnlyAnAdminCanCreateAnInvite(): void
    {
        $url = '/api/admin/suppliers/'.$this->supplier->getId()->toRfc4122().'/invite';

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->user(UserRole::Cliente));
        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheInviteIsStoredAsAHashAndReplacesThePreviousOne(): void
    {
        $this->supplier->setContactEmail('forn-'.bin2hex(random_bytes(3)).'@invite-test.example');
        $this->em->flush();
        $this->client->loginUser($this->user(UserRole::Admin));
        $url = '/api/admin/suppliers/'.$this->supplier->getId()->toRfc4122().'/invite';

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json()['dati'];
        $this->send('POST', $url);
        $second = $this->json()['dati'];

        self::assertSame(7, $second['scadenza_giorni']);
        self::assertStringContainsString($second['token'], $second['link']);
        $this->em->clear();
        $invites = $this->em->getRepository(SupplierInvite::class)->findBy([], ['createdAt' => 'ASC']);
        self::assertCount(2, $invites);
        self::assertSame(hash('sha256', $first['token']), $invites[0]->getTokenHash());
        self::assertTrue($invites[0]->isUsed(), 'Il primo invito non è più valido.');
        self::assertFalse($invites[1]->isUsed());

        $this->send('GET', '/api/suppliers/invite/'.$first['token']);
        self::assertResponseStatusCodeSame(410);
        $this->send('GET', '/api/suppliers/invite/'.$second['token']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testInviteNeedsAnEmailAnUnlinkedSupplierAndAnExistingSupplier(): void
    {
        $this->client->loginUser($this->user(UserRole::Admin));

        $this->send('POST', '/api/admin/suppliers/'.$this->supplier->getId()->toRfc4122().'/invite');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('EMAIL_MANCANTE', $this->json()['errore']['codice']);

        $this->send('POST', '/api/admin/suppliers/018f0000-0000-7000-8000-000000000000/invite');
        self::assertResponseStatusCodeSame(404);

        // Dopo la seconda richiesta il kernel di test svuota l'EntityManager: si rilegge la scheda dal database.
        $supplier = $this->em->find(Supplier::class, $this->supplier->getId()) ?? self::fail('Scheda non trovata.');
        $supplier->setContactEmail('x@invite-test.example')->setUser($this->user(UserRole::Fornitore));
        $this->em->flush();
        $this->send('POST', '/api/admin/suppliers/'.$this->supplier->getId()->toRfc4122().'/invite');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_COLLEGATO', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Attivazione ------------------------------ */

    public function testAValidInviteShowsTheCompanyAndTheEmail(): void
    {
        $token = $this->invite('azienda@invite-test.example');

        $this->send('GET', '/api/suppliers/invite/'.$token);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['nome_azienda' => 'Fornitore chiusura test', 'email' => 'azienda@invite-test.example'], $this->json()['dati']);
    }

    public function testUnknownExpiredOrUsedInvitesReturn410(): void
    {
        $this->send('GET', '/api/suppliers/invite/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(410);
        self::assertSame('INVITO_NON_VALIDO', $this->json()['errore']['codice']);

        $expired = $this->invite('scaduto@invite-test.example', '-1 hour');
        $this->send('GET', '/api/suppliers/invite/'.$expired);
        self::assertResponseStatusCodeSame(410);
        $this->send('POST', '/api/suppliers/activate', ['token' => $expired, 'password' => 'Password1', 'privacy' => true]);
        self::assertResponseStatusCodeSame(410);
    }

    public function testActivationCreatesTheSupplierAccountLogsInAndCannotBeReused(): void
    {
        $email = 'nuovo-'.bin2hex(random_bytes(3)).'@invite-test.example';
        $token = $this->invite($email);

        $this->send('POST', '/api/suppliers/activate', ['token' => $token, 'password' => 'Password1', 'privacy' => true]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('fornitore', $this->json()['dati']['ruolo']);
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertSame(UserRole::Fornitore, $user?->getRole());
        self::assertNotNull($user->getHashedPassword());
        self::assertNotSame('Password1', $user->getHashedPassword());
        self::assertNotNull($user->getPrivacyConsentedAt());
        $supplier = $this->em->getRepository(Supplier::class)->find($this->supplier->getId());
        self::assertSame($user->getId()->toRfc4122(), $supplier?->getUser()?->getId()->toRfc4122());

        // Accesso immediato: l'area fornitore risponde.
        $this->send('GET', '/api/supplier/orders');
        self::assertResponseStatusCodeSame(200);

        // Lo stesso link non si riusa.
        $this->send('POST', '/api/suppliers/activate', ['token' => $token, 'password' => 'Password1', 'privacy' => true]);
        self::assertResponseStatusCodeSame(410);
    }

    public function testActivationWithAnEmailAlreadyRegisteredReturns409(): void
    {
        $existing = $this->user(UserRole::Cliente);
        $token = $this->invite($existing->getEmail());

        $this->send('POST', '/api/suppliers/activate', ['token' => $token, 'password' => 'Password1', 'privacy' => true]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('EMAIL_ESISTENTE', $this->json()['errore']['codice']);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Supplier::class)->find($this->supplier->getId())?->getUser());
    }

    /**
     * @dataProvider badActivations
     *
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badActivations')]
    public function testActivationNeedsTokenStrongPasswordAndPrivacy(array $override, string $code): void
    {
        $token = $this->invite('x-'.bin2hex(random_bytes(3)).'@invite-test.example');
        $data = array_merge(['token' => $token, 'password' => 'Password1', 'privacy' => true], $override);

        $this->send('POST', '/api/suppliers/activate', $data);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($code, $this->json()['errore']['codice']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badActivations(): iterable
    {
        yield 'senza token' => [['token' => ''], 'TOKEN_MANCANTE'];
        yield 'password debole' => [['password' => 'corta1'], 'PASSWORD_DEBOLE'];
        yield 'password senza numero' => [['password' => 'SoloLettere'], 'PASSWORD_DEBOLE'];
        yield 'privacy non accettata' => [['privacy' => false], 'PRIVACY_NON_ACCETTATA'];
    }

    /* ------------------------------ Ordini del fornitore ------------------------------ */

    public function testTheSupplierAreaNeedsASupplierAccount(): void
    {
        $this->send('GET', '/api/supplier/orders');
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->user(UserRole::Cliente));
        $this->send('GET', '/api/supplier/orders');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->user(UserRole::Fornitore)); // fornitore senza scheda azienda collegata
        $this->send('GET', '/api/supplier/orders');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORNITORE_NON_COLLEGATO', $this->json()['errore']['codice']);
    }

    public function testTheSupplierSeesOnlyItsOwnOrdersWithoutCustomerData(): void
    {
        [$user, $mine] = $this->supplierWithOrder();
        $other = new Supplier('Altro fornitore');
        $this->em->persist($other);
        $foreignCampaign = $this->group($this->em, quantity: 10, status: CampaignStatus::OrdineFornitore);
        $this->member($this->em, $foreignCampaign, ParticipationStatus::Pagata);
        $this->em->persist(new SupplierOrder($foreignCampaign, $other, 'ORD-ALTRO-1', (int) date('Y'), 10, 9000));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('GET', '/api/supplier/orders');

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertCount(1, $dati['ordini']);
        $row = $dati['ordini'][0];
        self::assertSame($mine->getId()->toRfc4122(), $row['id']);
        self::assertSame('da_confermare', $row['stato_fornitore']);
        self::assertSame(22, $row['quantita_ordinata']);
        self::assertSame(800, $row['prezzo_unitario_cents']);
        self::assertSame(17600, $row['importo_cents']);
        self::assertSame(['conferma'], $row['azioni']);
        self::assertNotNull($row['scadenza_conferma']);
        self::assertSame(['da_confermare' => 1, 'in_preparazione' => 0, 'spediti' => 0, 'completati' => 0], $dati['contatori']);
        $json = json_encode($dati, \JSON_THROW_ON_ERROR);
        foreach (['@closure-test', 'commission', 'commissione', 'acconto', 'cliente'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
    }

    public function testConfirmingMovesTheOrderToPreparationOnlyOnce(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);
        $url = '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm';

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('in_preparazione', $this->json()['dati']['stato_fornitore']);
        $this->em->clear();
        $stored = $this->em->find(SupplierOrder::class, $order->getId());
        self::assertSame(SupplierOrderStatus::InPreparazione, $stored?->getStatus());
        self::assertNotNull($stored->getConfirmedAt());

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testASupplierCannotTouchSomeoneElsesOrder(): void
    {
        [, $order] = $this->supplierWithOrder();
        $intruder = $this->user(UserRole::Fornitore);
        $this->em->persist((new Supplier('Intruso'))->setUser($intruder));
        $this->em->flush();
        $this->client->loginUser($intruder);

        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm');
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment());
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/supplier/orders/018f0000-0000-7000-8000-000000000000/confirm');
        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertSame(SupplierOrderStatus::Inviato, $this->em->find(SupplierOrder::class, $order->getId())?->getStatus());
    }

    public function testShippingBeforeConfirmingSkipsAStepAndIsRefused(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);

        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testShippingStoresCarrierTrackingAndDate(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm');

        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('spedito', $this->json()['dati']['stato_fornitore']);
        $this->em->clear();
        $stored = $this->em->find(SupplierOrder::class, $order->getId());
        self::assertSame(SupplierOrderStatus::Spedito, $stored?->getStatus());
        self::assertSame('BRT', $stored->getCarrier());
        self::assertSame('BRT1234567', $stored->getTrackingNumber());
        self::assertSame(date('Y-m-d'), $stored->getShippedOn()?->format('Y-m-d'));

        // Una volta spedito non si rispedisce né si riconferma.
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment());
        self::assertResponseStatusCodeSame(409);
        $this->send('GET', '/api/supplier/orders');
        self::assertSame([], $this->json()['dati']['ordini'][0]['azioni']);
    }

    public function testInvalidShipmentDataReturns422WithTheFieldsToFix(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm');
        $url = '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship';

        $this->send('POST', $url, ['corriere' => 'Piccione', 'tracking' => '!', 'data_spedizione' => 'ieri']);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['corriere', 'tracking', 'data_spedizione'], array_keys($this->json()['errore']['campi']));

        $this->send('POST', $url, ['corriere' => 'Altro', 'tracking' => 'ABC12345', 'data_spedizione' => date('Y-m-d')]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('corriere_altro', $this->json()['errore']['campi']);

        $this->send('POST', $url, $this->shipment(['data_spedizione' => date('Y-m-d', strtotime('+1 day'))]));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('data_spedizione', $this->json()['errore']['campi']);

        $this->send('POST', $url, $this->shipment(['data_spedizione' => date('Y-m-d', strtotime('-2 days'))]));
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertSame(SupplierOrderStatus::InPreparazione, $this->em->find(SupplierOrder::class, $order->getId())?->getStatus());
    }

    public function testAnotherCarrierNameIsAcceptedWithOther(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm');

        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment(['corriere' => 'Altro', 'corriere_altro' => 'Trasporti Rossi']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Trasporti Rossi', $this->json()['dati']['corriere']);
    }

    public function testGoodsArrivedAfterShippingMakesTheOrderCompletedAndTheGroupDelivered(): void
    {
        [$user, $order] = $this->supplierWithOrder();
        $this->client->loginUser($user);
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/confirm');
        $this->send('POST', '/api/supplier/orders/'.$order->getId()->toRfc4122().'/ship', $this->shipment());
        $campaignId = $order->getCampaign()->getId()->toRfc4122();

        $this->client->loginUser($this->user(UserRole::Admin));
        $this->send('POST', '/api/campaigns/'.$campaignId.'/goods-arrived');

        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        self::assertSame(SupplierOrderStatus::Evaso, $this->em->find(SupplierOrder::class, $order->getId())?->getStatus());

        $this->client->loginUser($user);
        $this->send('GET', '/api/supplier/orders');
        self::assertSame('completato', $this->json()['dati']['ordini'][0]['stato_fornitore']);
        self::assertNotNull($this->json()['dati']['ordini'][0]['data_arrivo']);
    }

    /* ------------------------------ Aiuti ------------------------------ */

    /**
     * Fornitore con account, collegato alla scheda di prova, e un ordine "inviato" di 22 unità a 8,00 €.
     *
     * @return array{User, SupplierOrder}
     */
    private function supplierWithOrder(): array
    {
        $user = $this->user(UserRole::Fornitore);
        $this->supplier->setUser($user);
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);
        $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $order = new SupplierOrder($campaign, $this->supplier, 'ORD-TEST-'.++$this->fixtureCounter, (int) date('Y'), 22, 17600);
        $this->em->persist($order);
        $this->em->flush();

        return [$user, $order];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function shipment(array $override = []): array
    {
        return array_merge(['corriere' => 'BRT', 'tracking' => 'BRT1234567', 'data_spedizione' => date('Y-m-d')], $override);
    }

    /** Crea un invito per la scheda di prova e restituisce il token in chiaro. */
    private function invite(string $email, string $expires = '+7 days'): string
    {
        $token = bin2hex(random_bytes(32));
        $this->supplier->setContactEmail($email);
        $this->em->persist(new SupplierInvite($this->supplier, $email, SupplierInviteService::hash($token), new \DateTimeImmutable($expires)));
        $this->em->flush();

        return $token;
    }

    private function user(UserRole $role): User
    {
        $user = new User('Prova', 'u'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@supplier-test.example');
        $user->setRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $method, string $uri, array $data = []): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: 'GET' === $method ? null : json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
