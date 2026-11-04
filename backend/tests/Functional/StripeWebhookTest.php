<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Entity\StripeEvent;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-07: webhook di Stripe — firma, idempotenza, acconto → adesione, saldo → pagata.
 */
final class StripeWebhookTest extends WebTestCase
{
    private const SECRET = 'whsec_test_secret_for_phpunit';

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

        $this->supplier = new Supplier('Fornitore webhook test');
        $this->pickupPoint = new PickupPoint('Sede webhook test', 'Via Prova 1', 'Perugia');
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

    /* ------------------------------ Firma ------------------------------ */

    public function testWebhookWithoutSignatureReturns400AndChangesNothing(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $payload = $this->depositEvent('evt_nosig', 'pi_nosig', $campaign, $user);

        $this->client->request('POST', '/api/stripe/webhook', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseStatusCodeSame(400);
        $this->assertNothingChanged($campaign);
    }

    public function testWebhookWithInvalidSignatureReturns400AndChangesNothing(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $payload = $this->depositEvent('evt_badsig', 'pi_badsig', $campaign, $user);
        $forged = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'segreto_sbagliato');

        $this->post($payload, $forged);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('FIRMA_NON_VALIDA', $this->json()['errore']['codice']);
        $this->assertNothingChanged($campaign);
    }

    public function testSignatureOfADifferentBodyIsRejected(): void
    {
        $campaign = $this->campaign();
        $user = $this->customer();
        $genuine = $this->depositEvent('evt_a', 'pi_a', $campaign, $user, quantity: 1);
        $tampered = $this->depositEvent('evt_a', 'pi_a', $campaign, $user, quantity: 50);

        $this->post($tampered, $this->signature($genuine));

        self::assertResponseStatusCodeSame(400);
        $this->assertNothingChanged($campaign);
    }

    public function testStaleSignatureIsRejected(): void
    {
        $campaign = $this->campaign();
        $payload = $this->depositEvent('evt_old', 'pi_old', $campaign, $this->customer());

        $this->post($payload, $this->signature($payload, time() - 3600));

        self::assertResponseStatusCodeSame(400);
        $this->assertNothingChanged($campaign);
    }

    public function testSignedButMalformedBodyReturns400(): void
    {
        $this->post('non json', $this->signature('non json'));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('PAYLOAD_NON_VALIDO', $this->json()['errore']['codice']);

        $noId = json_encode(['type' => 'payment_intent.succeeded']);
        $this->post($noId, $this->signature($noId));
        self::assertResponseStatusCodeSame(400);
    }

    /* ------------------------------ Acconto ------------------------------ */

    public function testSignedDepositEventCreatesTheParticipation(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();

        $this->postEvent($this->depositEvent('evt_dep', 'pi_dep', $campaign, $user, quantity: 3, amount: 540));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['received' => true], $this->json());
        $participation = $this->participation($campaign, $user);
        self::assertSame(3, $participation->getQuantity());
        self::assertSame(540, $participation->getDepositCents());
        $campaign = $this->reload($campaign);
        self::assertSame(3, $campaign->getCurrentQuantity());
        $event = $this->em->getRepository(StripeEvent::class)->findOneBy(['eventId' => 'evt_dep']);
        self::assertNotNull($event);
        self::assertTrue($event->isProcessed());
        self::assertSame('payment_intent.succeeded', $event->getEventType());
    }

    public function testTheSameEventTwiceCreatesNoDuplicates(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();
        $payload = $this->depositEvent('evt_twice', 'pi_twice', $campaign, $user, quantity: 2, amount: 400);

        $this->postEvent($payload);
        $this->postEvent($payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['received' => true, 'duplicate' => true], $this->json());
        self::assertSame(1, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
        self::assertSame(1, $this->em->getRepository(Payment::class)->count(['stripePaymentIntentId' => 'pi_twice']));
        self::assertSame(1, $this->em->getRepository(StripeEvent::class)->count(['eventId' => 'evt_twice']));
        $campaign = $this->reload($campaign);
        self::assertSame(2, $campaign->getCurrentQuantity());
    }

    public function testTheSamePaymentSentUnderADifferentEventIdIsStillHandledOnce(): void
    {
        $campaign = $this->campaign(moq: 10);
        $user = $this->customer();

        $this->postEvent($this->depositEvent('evt_1', 'pi_same', $campaign, $user, quantity: 2, amount: 400));
        $this->postEvent($this->depositEvent('evt_2', 'pi_same', $campaign, $user, quantity: 2, amount: 400));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
        $campaign = $this->reload($campaign);
        self::assertSame(2, $campaign->getCurrentQuantity());
        self::assertSame([], $this->stripe->refunds);
    }

    public function testFailedProcessingReturns500AndTheEventIsRetriedAndThenSucceeds(): void
    {
        // Gruppo scaduto: il pagamento va rimborsato, ma il rimborso fallisce → l'evento non si chiude.
        $campaign = $this->campaign(moq: 10, deadline: '-1 hour');
        $user = $this->customer();
        $payload = $this->depositEvent('evt_retry', 'pi_retry', $campaign, $user);
        $this->stripe->failRefunds = true;

        $this->postEvent($payload);

        self::assertResponseStatusCodeSame(500);
        $event = $this->em->getRepository(StripeEvent::class)->findOneBy(['eventId' => 'evt_retry']);
        self::assertNotNull($event);
        self::assertFalse($event->isProcessed());

        $this->stripe->failRefunds = false;
        $this->postEvent($payload);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('rimborso_duplicato_pi_retry', $this->stripe->refunds);
        $event = $this->reload($event);
        self::assertTrue($event->isProcessed());
    }

    public function testOtherEventTypesAreRecordedAndIgnored(): void
    {
        $payload = json_encode(['id' => 'evt_other', 'type' => 'charge.updated', 'data' => ['object' => ['id' => 'ch_1']]], \JSON_THROW_ON_ERROR);

        $this->postEvent($payload);

        self::assertResponseStatusCodeSame(200);
        $event = $this->em->getRepository(StripeEvent::class)->findOneBy(['eventId' => 'evt_other']);
        self::assertNotNull($event);
        self::assertTrue($event->isProcessed());
    }

    public function testSucceededPaymentOfAnUnknownKindIsIgnored(): void
    {
        $payload = $this->intentEvent('evt_unknown', 'pi_unknown', ['tipo' => 'aumento'], 100);

        $this->postEvent($payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->em->getRepository(Payment::class)->count(['stripePaymentIntentId' => 'pi_unknown']));
    }

    /* ------------------------------ Saldo ------------------------------ */

    public function testSignedBalanceEventMarksTheParticipationAsPaid(): void
    {
        [$campaign, $user, $participation] = $this->confirmedParticipation();

        $this->postEvent($this->balanceEvent('evt_bal', 'pi_bal', $participation, $user, 2590));

        self::assertResponseStatusCodeSame(200);
        $participation = $this->reload($participation);
        self::assertSame(ParticipationStatus::Pagata, $participation->getStatus());
        self::assertNotNull($participation->getPaidAt());
        self::assertSame(2590, $participation->getBalanceCents());
        self::assertSame(240, $participation->getCommissionCents());
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['stripePaymentIntentId' => 'pi_bal']);
        self::assertNotNull($payment);
        self::assertSame(PaymentType::Saldo, $payment->getType());
        self::assertSame(PaymentStatus::Confermato, $payment->getStatus());
        self::assertSame(2590, $payment->getAmountCents());
        self::assertSame(240, $payment->getAgencyCommissionCents());
    }

    public function testBalanceEventTwiceIsHandledOnce(): void
    {
        [, $user, $participation] = $this->confirmedParticipation();
        $payload = $this->balanceEvent('evt_bal2', 'pi_bal2', $participation, $user, 2590);

        $this->postEvent($payload);
        $this->postEvent($payload);

        self::assertSame(1, $this->em->getRepository(Payment::class)->count(['stripePaymentIntentId' => 'pi_bal2']));
        self::assertSame([], $this->stripe->refunds);
    }

    public function testSecondBalancePaymentOnAnAlreadyPaidParticipationIsRefunded(): void
    {
        [, $user, $participation] = $this->confirmedParticipation();
        $this->postEvent($this->balanceEvent('evt_p1', 'pi_p1', $participation, $user, 2590));

        $this->postEvent($this->balanceEvent('evt_p2', 'pi_p2', $participation, $user, 2590));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['rimborso_duplicato_pi_p2' => 'pi_p2'], $this->stripe->refunds);
        $row = $this->em->getRepository(Payment::class)->findOneBy(['stripePaymentIntentId' => 'pi_p2']);
        self::assertNotNull($row);
        self::assertSame(PaymentStatus::Rimborsato, $row->getStatus());
        $participation = $this->reload($participation);
        self::assertSame(ParticipationStatus::Pagata, $participation->getStatus());
    }

    public function testBalancePaymentOnARefundedParticipationIsRefunded(): void
    {
        [, $user, $participation] = $this->confirmedParticipation();
        $participation->setStatus(ParticipationStatus::Rimborsata);
        $this->em->flush();

        $this->postEvent($this->balanceEvent('evt_r', 'pi_r', $participation, $user, 2590));

        self::assertArrayHasKey('rimborso_duplicato_pi_r', $this->stripe->refunds);
        $participation = $this->reload($participation);
        self::assertSame(ParticipationStatus::Rimborsata, $participation->getStatus());
    }

    public function testBalanceForAnotherUsersParticipationIsRefundedNotApplied(): void
    {
        [, , $participation] = $this->confirmedParticipation();
        $stranger = $this->customer();

        $this->postEvent($this->balanceEvent('evt_s', 'pi_s', $participation, $stranger, 2590));

        self::assertArrayHasKey('rimborso_duplicato_pi_s', $this->stripe->refunds);
        $participation = $this->reload($participation);
        self::assertSame(ParticipationStatus::Confermata, $participation->getStatus());
    }

    /* ------------------------------ Supporto ------------------------------ */

    /**
     * Adesione "confermata" pronta per il saldo: 3 unità, scaglione raggiunto 800, commissione 10%, spedizione a domicilio.
     * Saldo atteso: 3 × 800 = 2400 + 240 + 490 − 540 = 2590.
     *
     * @return array{Campaign, User, Participation}
     */
    private function confirmedParticipation(): array
    {
        $campaign = $this->campaign(moq: 10, tiers: [10 => 900, 20 => 800]);
        $campaign->setCurrentQuantity(22);
        $user = $this->customer();
        $participation = (new Participation($campaign, $user, 3))->setDepositCents(540)->setStatus(ParticipationStatus::Confermata);
        $delivery = new \App\Delivery\Entity\Delivery($participation, \App\Delivery\Enum\DeliveryMode::ConsegnaDomicilio);
        $delivery->setDeliveryCostCents(490);
        $this->em->persist($participation);
        $this->em->persist($delivery);
        $this->em->flush();

        return [$campaign, $user, $participation];
    }

    private function assertNothingChanged(Campaign $campaign): void
    {
        self::assertSame(0, $this->em->getRepository(Participation::class)->count(['campaign' => $campaign]));
        self::assertSame(0, $this->em->getRepository(StripeEvent::class)->count([]));
        $campaign = $this->reload($campaign);
        self::assertSame(0, $campaign->getCurrentQuantity());
        self::assertSame([], $this->stripe->refunds);
    }

    private function depositEvent(string $eventId, string $intentId, Campaign $campaign, User $user, int $quantity = 1, int $amount = 200): string
    {
        return $this->intentEvent($eventId, $intentId, [
            'tipo' => 'acconto',
            'colletta_id' => $campaign->getId()->toRfc4122(),
            'quantita' => (string) $quantity,
            'utente_id' => $user->getId()->toRfc4122(),
            'delivery_type' => 'ritiro_sede',
            'id_sede' => $this->pickupPoint->getId()->toRfc4122(),
            'indirizzo' => '',
        ], $amount);
    }

    private function balanceEvent(string $eventId, string $intentId, Participation $participation, User $user, int $amount): string
    {
        return $this->intentEvent($eventId, $intentId, [
            'tipo' => 'saldo',
            'prenotazione_id' => $participation->getId()->toRfc4122(),
            'colletta_id' => $participation->getCampaign()->getId()->toRfc4122(),
            'utente_id' => $user->getId()->toRfc4122(),
        ], $amount);
    }

    /**
     * @param array<string, string> $metadata
     */
    private function intentEvent(string $eventId, string $intentId, array $metadata, int $amount): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => $amount,
                'amount_received' => $amount,
                'status' => 'succeeded',
                'payment_method' => 'pm_card_test',
                'metadata' => $metadata,
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function signature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
    }

    private function postEvent(string $payload): void
    {
        $this->post($payload, $this->signature($payload));
    }

    private function post(string $payload, string $signature): void
    {
        $this->client->request('POST', '/api/stripe/webhook', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload);
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

    private function customer(): User
    {
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@webhook-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Dopo la seconda richiesta il kernel di test svuota l'EntityManager: le entità tenute dal test
     * non sono più gestite, quindi si rileggono dal database.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(object $entity): object
    {
        $id = array_values($this->em->getClassMetadata($entity::class)->getIdentifierValues($entity))[0];
        $this->em->clear();

        return $this->em->find($entity::class, $id) ?? self::fail('Entità non trovata.');
    }

    private function participation(Campaign $campaign, User $user): Participation
    {
        return $this->em->getRepository(Participation::class)->findOneBy(['campaign' => $campaign, 'user' => $user])
            ?? self::fail('Adesione non trovata.');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
