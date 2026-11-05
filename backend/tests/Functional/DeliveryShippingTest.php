<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Enum\DeliveryStatus;
use App\Identity\Entity\User;
use App\Identity\Enum\UserRole;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-20: l'admin segna come spedita una consegna a domicilio, solo dopo "Merce arrivata" e per adesioni pagate.
 */
final class DeliveryShippingTest extends WebTestCase
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

    public function testShippingNeedsAnAdmin(): void
    {
        [$member] = $this->paidHomeDelivery();

        $this->ship($this->deliveryId($member));
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($member->getUser());
        $this->ship($this->deliveryId($member));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminShipsAndTheCustomerIsNotifiedWithTheTracking(): void
    {
        [$member] = $this->paidHomeDelivery();
        $id = $this->deliveryId($member);
        $this->client->loginUser($this->admin());

        $this->ship($id, ['tracking' => "  BRT\x07 123456789  "]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['spedita' => true, 'tracking' => 'BRT 123456789'], $this->json()['dati']);
        $this->em->clear();
        $delivery = $this->em->find(Delivery::class, $id);
        self::assertSame(DeliveryStatus::Spedita, $delivery->getStatus());
        self::assertSame('BRT 123456789', $delivery->getTrackingNumber());
        self::assertNotNull($delivery->getShippedAt());
        $notifications = $this->em->getRepository(Notification::class)->findBy(['user' => $member->getUser()->getId(), 'type' => NotificationType::Spedito]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('Tracking: BRT 123456789', $notifications[0]->getMessage());
    }

    public function testTheTrackingIsOptional(): void
    {
        [$member] = $this->paidHomeDelivery();
        $this->client->loginUser($this->admin());

        $this->ship($this->deliveryId($member), ['tracking' => '   ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['spedita' => true, 'tracking' => null], $this->json()['dati']);
    }

    public function testATooLongTrackingIsRejected(): void
    {
        [$member] = $this->paidHomeDelivery();
        $this->client->loginUser($this->admin());

        $this->ship($this->deliveryId($member), ['tracking' => str_repeat('A', 121)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('TRACKING_TROPPO_LUNGO', $this->json()['errore']['codice']);
    }

    public function testShippingTwiceReturns409AndNotifiesOnce(): void
    {
        [$member] = $this->paidHomeDelivery();
        $id = $this->deliveryId($member);
        $this->client->loginUser($this->admin());

        $this->ship($id);
        $this->ship($id);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_SPEDITA', $this->json()['errore']['codice']);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy(['user' => $member->getUser()->getId(), 'type' => NotificationType::Spedito]));
    }

    public function testAnAlreadyReceivedDeliveryCannotBeShipped(): void
    {
        [$member] = $this->paidHomeDelivery();
        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $member]);
        $delivery->setStatus(DeliveryStatus::Consegnata);
        $this->em->flush();
        $this->client->loginUser($this->admin());

        $this->ship($delivery->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('GIA_CONSEGNATA', $this->json()['errore']['codice']);
    }

    public function testBeforeTheGoodsArriveTheShipmentIsBlocked(): void
    {
        [$member] = $this->paidHomeDelivery(CampaignStatus::OrdineFornitore);
        $this->client->loginUser($this->admin());

        $this->ship($this->deliveryId($member));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('SPEDIZIONE_BLOCCATA', $this->json()['errore']['codice']);
        $this->em->clear();
        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $member->getId()]);
        self::assertSame(DeliveryStatus::InAttesa, $delivery->getStatus());
        self::assertNull($delivery->getTrackingNumber());
    }

    public function testAnUnpaidParticipationCannotBeShipped(): void
    {
        [$member] = $this->paidHomeDelivery(CampaignStatus::Consegnata, ParticipationStatus::AzioneRichiesta);
        $this->client->loginUser($this->admin());

        $this->ship($this->deliveryId($member));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('NON_SPEDIBILE', $this->json()['errore']['codice']);
    }

    public function testAPickupDeliveryCannotBeShipped(): void
    {
        $campaign = $this->group($this->em, quantity: 22, status: CampaignStatus::Consegnata);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata, home: false);
        $this->client->loginUser($this->admin());

        $this->ship($this->deliveryId($member));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('NON_SPEDIBILE', $this->json()['errore']['codice']);
    }

    public function testUnknownDeliveriesReturn404(): void
    {
        $this->client->loginUser($this->admin());

        $this->ship('018f0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('CONSEGNA_INESISTENTE', $this->json()['errore']['codice']);
    }

    public function testTheCustomerSeesTheShipmentAndThenConfirmsTheReceipt(): void
    {
        [$member] = $this->paidHomeDelivery();
        $id = $this->deliveryId($member);
        $this->client->loginUser($this->admin());
        $this->ship($id, ['tracking' => 'GLS-998877']);
        self::assertResponseStatusCodeSame(200);

        $this->client->loginUser($member->getUser());
        $this->client->request('GET', '/api/me/purchases?tab=in-arrivo');
        $row = $this->json()['dati']['acquisti'][0];
        self::assertSame('GLS-998877', $row['tracking']);
        self::assertNotNull($row['spedito_il']);
        self::assertTrue($row['puo_confermare_ricezione']);

        $this->client->request('POST', '/api/deliveries/'.$id.'/confirm-receipt');
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Gruppo con una adesione pagata e consegna a domicilio ancora "in attesa".
     *
     * @return array{Participation, Campaign}
     */
    private function paidHomeDelivery(CampaignStatus $campaignStatus = CampaignStatus::Consegnata, ParticipationStatus $status = ParticipationStatus::Pagata): array
    {
        $campaign = $this->group($this->em, quantity: 22, status: $campaignStatus);
        $member = $this->member($this->em, $campaign, $status, home: true);

        return [$member, $campaign];
    }

    private function deliveryId(Participation $participation): string
    {
        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $participation->getId()]);

        return $delivery?->getId()->toRfc4122() ?? self::fail('Consegna non trovata.');
    }

    private function admin(): User
    {
        $user = new User('Admin', 'a'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@shipping-test.example');
        $user->setRole(UserRole::Admin);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ship(string $deliveryId, array $data = []): void
    {
        $this->client->request('POST', '/api/admin/deliveries/'.$deliveryId.'/ship', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
