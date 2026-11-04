<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Enum\PickupTokenStatus;
use App\Identity\Entity\User;
use App\Identity\Enum\UserRole;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-06 (scelta della consegna), US-08 (QR di ritiro) e US-09 (conferma di ricezione), compresa
 * "merce arrivata" che fa nascere i QR.
 */
final class DeliveryFlowTest extends WebTestCase
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

    /* ------------------------------ US-06: scelta ------------------------------ */

    public function testTheChoiceNeedsALogin(): void
    {
        $this->send('POST', '/api/deliveries/choice', []);
        self::assertResponseStatusCodeSame(401);

        $this->send('GET', '/api/deliveries/cost');
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheCostEndpointReturnsTheFixedHomeDeliveryCost(): void
    {
        $this->client->loginUser($this->plainUser());

        $this->send('GET', '/api/deliveries/cost');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(490, $this->json()['dati']['costo_spedizione_cents']);
    }

    public function testHomeDeliveryStoresTheFixedCostAndTheProfileAddress(): void
    {
        $member = $this->confirmed();
        $member->getUser()->setAddress('Via Roma 1')->setZipCode('06100')->setCity('Perugia')->setProvince('pg');
        $this->em->flush();
        $this->client->loginUser($member->getUser());

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'consegna_domicilio']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(490, $this->json()['dati']['importo_consegna_cents']);
        $delivery = $this->deliveryOf($member);
        self::assertSame(DeliveryMode::ConsegnaDomicilio, $delivery->getMode());
        self::assertSame(490, $delivery->getDeliveryCostCents());
        self::assertSame('Via Roma 1, 06100 Perugia (PG)', $delivery->getDeliveryAddress());
        self::assertNull($delivery->getPickupPoint());
    }

    public function testHomeDeliveryWithoutACompleteAddressReturns422WithTheMissingFields(): void
    {
        $member = $this->confirmed();
        $this->client->loginUser($member->getUser());

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'consegna_domicilio', 'indirizzo' => ['via' => 'Via Roma 1']]);

        self::assertResponseStatusCodeSame(422);
        $error = $this->json()['errore'];
        self::assertSame('INDIRIZZO_MANCANTE', $error['codice']);
        self::assertArrayHasKey('cap', $error['campi']);
        self::assertArrayHasKey('citta', $error['campi']);
        self::assertArrayNotHasKey('via', $error['campi']);
        self::assertSame(DeliveryMode::RitiroSede, $this->deliveryOf($member)->getMode());
    }

    public function testTheAddressInTheRequestWinsOverTheProfile(): void
    {
        $member = $this->confirmed();
        $member->getUser()->setAddress('Via Vecchia 9')->setZipCode('00100')->setCity('Roma');
        $this->em->flush();
        $this->client->loginUser($member->getUser());

        $this->send('POST', '/api/deliveries/choice', [
            'prenotazione_id' => $member->getId()->toRfc4122(),
            'modalita' => 'consegna_domicilio',
            'indirizzo' => ['via' => 'Via Nuova 2', 'cap' => '20100', 'citta' => 'Milano', 'provincia' => 'mi'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Via Nuova 2, 20100 Milano (MI)', $this->deliveryOf($member)->getDeliveryAddress());
    }

    public function testChoosingPickupAgainBringsTheCostBackToZero(): void
    {
        $member = $this->confirmed(home: true);
        $this->client->loginUser($member->getUser());

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'ritiro_sede']);

        self::assertResponseStatusCodeSame(200);
        $delivery = $this->deliveryOf($member);
        self::assertSame(DeliveryMode::RitiroSede, $delivery->getMode());
        self::assertSame(0, $delivery->getDeliveryCostCents());
        self::assertNull($delivery->getDeliveryAddress());
        self::assertNotNull($delivery->getPickupPoint());
    }

    /**
     * @dataProvider notConfirmedStatuses
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notConfirmedStatuses')]
    public function testTheChoiceIsRefusedUnlessTheParticipationIsConfirmed(ParticipationStatus $status): void
    {
        $campaign = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $campaign, $status);
        $this->client->loginUser($member->getUser());

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'ritiro_sede']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('SCELTA_NON_CONSENTITA', $this->json()['errore']['codice']);
    }

    /** @return iterable<string, array{ParticipationStatus}> */
    public static function notConfirmedStatuses(): iterable
    {
        yield 'prenotata' => [ParticipationStatus::Prenotata];
        yield 'pagata' => [ParticipationStatus::Pagata];
        yield 'rimborsata' => [ParticipationStatus::Rimborsata];
        yield 'azione richiesta' => [ParticipationStatus::AzioneRichiesta];
    }

    public function testSomeoneElsesParticipationOrABadModeIsRejected(): void
    {
        $member = $this->confirmed();
        $this->client->loginUser($this->plainUser());

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'ritiro_sede']);
        self::assertResponseStatusCodeSame(404);

        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => 'non-un-uuid', 'modalita' => 'ritiro_sede']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($member->getUser());
        $this->send('POST', '/api/deliveries/choice', ['prenotazione_id' => $member->getId()->toRfc4122(), 'modalita' => 'corriere']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('MODALITA_NON_VALIDA', $this->json()['errore']['codice']);
    }

    /* ------------------------------ Merce arrivata ------------------------------ */

    public function testGoodsArrivedIsAdminOnly(): void
    {
        [$campaign] = $this->orderedGroup();
        $url = '/api/campaigns/'.$campaign->getId()->toRfc4122().'/goods-arrived';

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->plainUser());
        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGoodsArrivedMovesTheGroupCreatesQrOnlyForPickupAndNotifies(): void
    {
        [$campaign, $pickup, $home] = $this->orderedGroup();
        $this->client->loginUser($this->plainUser(UserRole::Admin));

        $this->send('POST', '/api/campaigns/'.$campaign->getId()->toRfc4122().'/goods-arrived');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ritiri' => 1, 'spedizioni' => 1], $this->json()['dati']);
        $this->em->clear();
        self::assertSame(CampaignStatus::Consegnata, $this->em->find(Campaign::class, $campaign->getId())?->getStatus());
        $order = $this->em->getRepository(SupplierOrder::class)->findOneBy([]);
        self::assertSame(SupplierOrderStatus::Evaso, $order?->getStatus());
        self::assertCount(1, $this->em->getRepository(PickupToken::class)->findAll());
        self::assertNotNull($this->em->getRepository(PickupToken::class)->findOneBy(['participation' => $pickup->getId()]));
        self::assertNull($this->em->getRepository(PickupToken::class)->findOneBy(['participation' => $home->getId()]));
        self::assertSame(DeliveryStatus::Pronta, $this->deliveryOf($pickup)->getStatus());
        self::assertSame(DeliveryStatus::InAttesa, $this->deliveryOf($home)->getStatus());
        self::assertCount(1, $this->notificationsOf($pickup->getUser(), NotificationType::MercePronta));
        self::assertCount(0, $this->notificationsOf($home->getUser(), NotificationType::MercePronta));
    }

    public function testGoodsArrivedTwiceOrOutOfOrderReturns409(): void
    {
        [$campaign] = $this->orderedGroup();
        $early = $this->group($this->em, quantity: 22);
        $url = '/api/campaigns/'.$campaign->getId()->toRfc4122().'/goods-arrived';
        $this->client->loginUser($this->plainUser(UserRole::Admin));

        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(200);
        $this->send('POST', $url);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('STATO_NON_VALIDO', $this->json()['errore']['codice']);

        $this->send('POST', '/api/campaigns/'.$early->getId()->toRfc4122().'/goods-arrived');
        self::assertResponseStatusCodeSame(409);
    }

    /* ------------------------------ US-08: QR ------------------------------ */

    public function testTheOwnerSeesTheQrWithTokenQuantityAndStatus(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($pickup->getUser());

        $this->send('GET', $this->qrUrl($pickup));

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $dati['token']);
        self::assertSame(3, $dati['quantita_assegnata']);
        self::assertSame('generato', $dati['stato']);
        self::assertFalse($dati['scansionato']);
    }

    public function testTheDatabaseKeepsOnlyTheHashOfTheDisplayedToken(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($pickup->getUser());

        $this->send('GET', $this->qrUrl($pickup));
        $token = $this->json()['dati']['token'];

        $stored = $this->em->getRepository(PickupToken::class)->findOneBy(['participation' => $pickup->getId()]);
        self::assertNotSame($token, $stored?->getTokenHash());
        self::assertSame(hash('sha256', $token), $stored?->getTokenHash());
    }

    public function testEachDisplayIssuesANewTokenAndTheOldOneStopsWorking(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($pickup->getUser());
        $this->send('GET', $this->qrUrl($pickup));
        $first = $this->json()['dati']['token'];
        $this->send('GET', $this->qrUrl($pickup));
        $second = $this->json()['dati']['token'];
        self::assertNotSame($first, $second);

        $this->client->loginUser($this->plainUser(UserRole::Admin));
        $this->send('POST', '/api/pickups/'.$first.'/scan');
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/pickups/'.$second.'/scan');
        self::assertResponseStatusCodeSame(200);
    }

    public function testAnotherUserSeesNoQr(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($this->plainUser());

        $this->send('GET', $this->qrUrl($pickup));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('QR_NON_TROVATO', $this->json()['errore']['codice']);
    }

    public function testHomeDeliveryHasNoQr(): void
    {
        [, , $home] = $this->arrived();
        $this->client->loginUser($home->getUser());

        $this->send('GET', $this->qrUrl($home));

        self::assertResponseStatusCodeSame(404);
    }

    public function testThereIsNoQrBeforeTheGoodsArrive(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->client->loginUser($member->getUser());

        $this->send('GET', $this->qrUrl($member));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminScanMarksThePickupOnceAndNotifiesTheCustomer(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($pickup->getUser());
        $this->send('GET', $this->qrUrl($pickup));
        $token = $this->json()['dati']['token'];
        $this->client->loginUser($this->plainUser(UserRole::Admin));

        $this->send('POST', '/api/pickups/'.$token.'/scan');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->json()['dati']['quantita']);
        $this->em->clear();
        $stored = $this->em->getRepository(PickupToken::class)->findOneBy(['participation' => $pickup->getId()]);
        self::assertSame(PickupTokenStatus::Scansionato, $stored?->getStatus());
        self::assertNotNull($stored->getScannedAt());
        $delivery = $this->deliveryOf($pickup);
        self::assertSame(DeliveryStatus::Ritirata, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
        self::assertCount(1, $this->notificationsOf($pickup->getUser(), NotificationType::Ritirato));

        // Riutilizzo: 409 e nessuna seconda notifica.
        $this->send('POST', '/api/pickups/'.$token.'/scan');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_RITIRATO', $this->json()['errore']['codice']);
        self::assertCount(1, $this->notificationsOf($pickup->getUser(), NotificationType::Ritirato));
    }

    public function testAfterThePickupTheQrShowsTheStatusWithoutANewToken(): void
    {
        [, $pickup] = $this->arrived();
        $this->client->loginUser($pickup->getUser());
        $this->send('GET', $this->qrUrl($pickup));
        $token = $this->json()['dati']['token'];
        $this->client->loginUser($this->plainUser(UserRole::Admin));
        $this->send('POST', '/api/pickups/'.$token.'/scan');

        $this->client->loginUser($pickup->getUser());
        $this->send('GET', $this->qrUrl($pickup));

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertNull($dati['token']);
        self::assertTrue($dati['scansionato']);
        self::assertNotNull($dati['data_scansione']);
    }

    public function testScanWithAnUnknownTokenReturns404AndOnlyAnAdminCanScan(): void
    {
        $this->client->loginUser($this->plainUser(UserRole::Admin));
        $this->send('POST', '/api/pickups/'.str_repeat('a', 48).'/scan');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('TOKEN_NON_VALIDO', $this->json()['errore']['codice']);

        $this->client->loginUser($this->plainUser());
        $this->send('POST', '/api/pickups/'.str_repeat('a', 48).'/scan');
        self::assertResponseStatusCodeSame(403);
    }

    /* ------------------------------ US-09: ricezione ------------------------------ */

    public function testTheOwnerConfirmsReceiptOfAShippedDeliveryOnce(): void
    {
        $member = $this->shipped();
        $delivery = $this->deliveryOf($member);
        $this->client->loginUser($member->getUser());

        $this->send('POST', $this->receiptUrl($delivery));

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['dati']['consegnata']);
        $reloaded = $this->deliveryOf($member);
        self::assertSame(DeliveryStatus::Consegnata, $reloaded->getStatus());
        self::assertNotNull($reloaded->getDeliveredAt());
        self::assertCount(1, $this->notificationsOf($member->getUser(), NotificationType::OrdineRicevuto));

        $this->send('POST', $this->receiptUrl($reloaded));
        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->notificationsOf($member->getUser(), NotificationType::OrdineRicevuto));
    }

    public function testAnotherUserCannotConfirmReceipt(): void
    {
        $member = $this->shipped();
        $delivery = $this->deliveryOf($member);
        $this->client->loginUser($this->plainUser());

        $this->send('POST', $this->receiptUrl($delivery));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DeliveryStatus::Spedita, $this->deliveryOf($member)->getStatus());
    }

    public function testReceiptIsRefusedWhileTheDeliveryIsNotShipped(): void
    {
        $member = $this->shipped(DeliveryStatus::InAttesa);
        $delivery = $this->deliveryOf($member);
        $this->client->loginUser($member->getUser());

        $this->send('POST', $this->receiptUrl($delivery));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(DeliveryStatus::InAttesa, $this->deliveryOf($member)->getStatus());
    }

    public function testReceiptOfAPickupOrAnUnknownDeliveryIsRefused(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::Consegnata);
        $pickup = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $delivery = $this->deliveryOf($pickup);
        $this->client->loginUser($pickup->getUser());

        $this->send('POST', $this->receiptUrl($delivery));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('NON_CONFERMABILE', $this->json()['errore']['codice']);

        $this->send('POST', '/api/deliveries/018f0000-0000-7000-8000-000000000000/confirm-receipt');
        self::assertResponseStatusCodeSame(404);
    }

    /* ------------------------------ Aiuti ------------------------------ */

    private function confirmed(bool $home = false): Participation
    {
        $campaign = $this->group($this->em, quantity: 22);

        return $this->member($this->em, $campaign, ParticipationStatus::Confermata, home: $home);
    }

    /**
     * Gruppo "ordine al fornitore" con ordine inviato e due adesioni pagate: ritiro in sede e spedizione.
     *
     * @return array{Campaign, Participation, Participation}
     */
    private function orderedGroup(): array
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::OrdineFornitore);
        $pickup = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $home = $this->member($this->em, $campaign, ParticipationStatus::Pagata, home: true);
        $order = new SupplierOrder($campaign, $this->supplier, 'ORD-TEST-'.++$this->fixtureCounter, (int) date('Y'), 22, 17600);
        $order->setStatus(SupplierOrderStatus::Inviato);
        $this->em->persist($order);
        $this->em->flush();

        return [$campaign, $pickup, $home];
    }

    /**
     * Come orderedGroup(), ma la merce è già arrivata (passando dall'endpoint, come in produzione).
     *
     * @return array{Campaign, Participation, Participation}
     */
    private function arrived(): array
    {
        [$campaign, $pickup, $home] = $this->orderedGroup();
        $this->client->loginUser($this->plainUser(UserRole::Admin));
        $this->send('POST', '/api/campaigns/'.$campaign->getId()->toRfc4122().'/goods-arrived');
        self::assertResponseStatusCodeSame(200);
        $this->em->clear();

        return [$campaign, $this->participation($pickup), $this->participation($home)];
    }

    private function shipped(DeliveryStatus $status = DeliveryStatus::Spedita): Participation
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::Consegnata);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata, home: true);
        $this->deliveryOf($member)->setStatus($status);
        $this->em->flush();

        return $member;
    }

    private function participation(Participation $participation): Participation
    {
        return $this->em->find(Participation::class, $participation->getId()) ?? self::fail('Adesione non trovata.');
    }

    private function deliveryOf(Participation $participation): Delivery
    {
        $this->em->clear();

        return $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $participation->getId()]) ?? self::fail('Consegna non trovata.');
    }

    /** @return list<Notification> */
    private function notificationsOf(User $user, NotificationType $type): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['user' => $user->getId(), 'type' => $type]);
    }

    private function plainUser(UserRole $role = UserRole::Cliente): User
    {
        $user = new User('Prova', 'u'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@delivery-test.example');
        $user->setRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function qrUrl(Participation $participation): string
    {
        return '/api/participations/'.$participation->getId()->toRfc4122().'/pickup-qr';
    }

    private function receiptUrl(Delivery $delivery): string
    {
        return '/api/deliveries/'.$delivery->getId()->toRfc4122().'/confirm-receipt';
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
