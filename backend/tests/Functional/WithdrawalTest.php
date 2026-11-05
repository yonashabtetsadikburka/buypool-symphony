<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Tests\Support\FakeStripeGateway;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-05: ritiro della propria adesione prima della scadenza e sotto il minimo, con rimborso dell'acconto.
 * Gruppo di prova: minimo 10 unità; l'adesione è di 3 unità con acconto di 5,40 €.
 */
final class WithdrawalTest extends WebTestCase
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

    public function testWithdrawalNeedsALogin(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');

        $this->send($campaign);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAValidWithdrawalRefundsTheDepositFreesTheUnitsAndKeepsTheHistory(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['stato' => 'in_corso', 'rimborsato_cents' => 540], $this->json()['dati']);
        self::assertSame(['rimborso_pi_dep_'.$this->fixtureCounter => 'pi_dep_'.$this->fixtureCounter], $this->stripe->refunds);
        $this->em->clear();
        $stored = $this->em->find(Participation::class, $member->getId());
        self::assertSame(ParticipationStatus::Annullata, $stored?->getStatus(), 'La riga resta, con lo stato annullata.');
        self::assertSame(3, $this->em->find(Campaign::class, $campaign->getId())?->getCurrentQuantity());
        $payments = $this->em->getRepository(Payment::class)->findBy(['participation' => $member->getId()]);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Rimborsato, $payments[0]->getStatus());
        $notifications = $this->em->getRepository(Notification::class)->findBy(['user' => $member->getUser()->getId(), 'type' => NotificationType::Rimborso]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('5,40', $notifications[0]->getMessage());
    }

    public function testWithdrawingTwiceFindsNoParticipationAndRefundsOnce(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);
        $this->send($campaign);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('NON_PARTECIPI', $this->json()['errore']['codice']);
        self::assertCount(1, $this->stripe->refunds);
        $this->em->clear();
        self::assertSame(3, $this->em->find(Campaign::class, $campaign->getId())?->getCurrentQuantity());
    }

    public function testAfterTheDeadlineWithdrawalReturns409(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '-1 hour');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CAMPAGNA_SCADUTA', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->refunds);
    }

    /**
     * @dataProvider paidStatuses
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('paidStatuses')]
    public function testAPaidParticipationCannotBeWithdrawnAndReturns403(ParticipationStatus $status): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, $status);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('PAGAMENTO_EFFETTUATO', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->refunds);
    }

    /** @return iterable<string, array{ParticipationStatus}> */
    public static function paidStatuses(): iterable
    {
        yield 'pagata' => [ParticipationStatus::Pagata];
        yield 'rimborsata' => [ParticipationStatus::Rimborsata];
    }

    public function testOnceTheMinimumIsReachedTheParticipationCannotBeWithdrawn(): void
    {
        $campaign = $this->group($this->em, quantity: 12, deadline: '+3 days', status: CampaignStatus::Riuscita);
        $confirmed = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $this->client->loginUser($confirmed->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('ANNULLO_NON_CONSENTITO', $this->json()['errore']['codice']);
        self::assertSame([], $this->stripe->refunds);
        $this->em->clear();
        self::assertSame(12, $this->em->find(Campaign::class, $campaign->getId())?->getCurrentQuantity());
    }

    public function testAReservedParticipationOfAGroupExactlyAtTheMinimumIsLocked(): void
    {
        $campaign = $this->group($this->em, quantity: 10, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('ANNULLO_NON_CONSENTITO', $this->json()['errore']['codice']);
    }

    /**
     * @dataProvider lateStatuses
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lateStatuses')]
    public function testAnOrderedOrDeliveredGroupCannotBeLeft(CampaignStatus $status, string $code): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '+3 days', status: $status);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($code, $this->json()['errore']['codice']);
    }

    /** @return iterable<string, array{CampaignStatus, string}> */
    public static function lateStatuses(): iterable
    {
        yield 'ordine al fornitore' => [CampaignStatus::OrdineFornitore, 'CAMPAGNA_ORDINATA'];
        yield 'consegnata' => [CampaignStatus::Consegnata, 'CAMPAGNA_CONSEGNATA'];
    }

    public function testSomeoneWithoutAParticipationAndUnknownGroupsReturn404(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($this->outsider());

        $this->send($campaign);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NON_PARTECIPI', $this->json()['errore']['codice']);

        $this->client->request('DELETE', '/api/campaigns/018f0000-0000-7000-8000-000000000000/participation');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('CAMPAGNA_INESISTENTE', $this->json()['errore']['codice']);

        // L'adesione di un altro non si tocca.
        $this->em->clear();
        self::assertSame(ParticipationStatus::Prenotata, $this->em->find(Participation::class, $member->getId())?->getStatus());
    }

    public function testAFailedRefundLeavesEverythingAsItWasAndReturns500(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->stripe->failRefunds = true;
        $this->client->loginUser($member->getUser());

        $this->send($campaign);

        self::assertResponseStatusCodeSame(500);
        self::assertSame('RIMBORSO_FALLITO', $this->json()['errore']['codice']);
        $this->em->clear();
        self::assertSame(ParticipationStatus::Prenotata, $this->em->find(Participation::class, $member->getId())?->getStatus());
        self::assertSame(6, $this->em->find(Campaign::class, $campaign->getId())?->getCurrentQuantity());
        $payments = $this->em->getRepository(Payment::class)->findBy(['participation' => $member->getId()]);
        self::assertSame(PaymentStatus::Confermato, $payments[0]->getStatus());
        self::assertCount(0, $this->em->getRepository(Notification::class)->findBy(['user' => $member->getUser()->getId()]));
    }

    private function outsider(): User
    {
        $user = new User('Estraneo', 'o'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@withdraw-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function send(Campaign $campaign): void
    {
        $this->client->request('DELETE', '/api/campaigns/'.$campaign->getId()->toRfc4122().'/participation');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
