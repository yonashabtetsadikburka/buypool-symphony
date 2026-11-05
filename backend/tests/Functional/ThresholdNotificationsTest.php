<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Service\DepositConfirmationService;
use App\Participation\Service\PaidDeposit;
use App\Supplier\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * US-25: le notifiche di soglia nascono quando serve e una volta sola (acconto addebitato, minimo raggiunto, prezzo sceso).
 * Gruppo di prova: minimo 10 unità (10 → 9,00 €, 20 → 8,00 €), commissione 10%.
 */
final class ThresholdNotificationsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DepositConfirmationService $service;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(DepositConfirmationService::class);
        $this->em->getConnection()->beginTransaction();

        $this->supplier = new Supplier('Fornitore soglie test');
        $this->pickupPoint = new PickupPoint('Sede soglie test', 'Via Prova 1', 'Perugia');
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

    public function testADepositBelowTheMinimumOnlyTellsTheBuyerWhatWasCharged(): void
    {
        $campaign = $this->campaign(quantity: 0);
        $buyer = $this->customer();

        $this->service->confirm($this->paid($campaign, $buyer, quantity: 3, amount: 540, pi: 'pi_n1'));

        $confirmed = $this->notificationsOf($buyer, NotificationType::OrdineConfermato);
        self::assertCount(1, $confirmed);
        self::assertSame('Acconto addebitato', $confirmed[0]->getTitle());
        // Stima del saldo: 3 × 9,00 = 27,00 + commissione 2,70 − acconto 5,40 = 24,30.
        self::assertStringContainsString('5,40 € per Prodotto soglie 1 (3 unità)', $confirmed[0]->getMessage());
        self::assertStringContainsString('24,30 €', $confirmed[0]->getMessage());
        self::assertSame([], $this->notificationsOf($buyer, NotificationType::MoqRaggiunto));
        self::assertSame([], $this->notificationsOf($buyer, NotificationType::NuovoScaglione));
    }

    public function testReachingTheMinimumTellsEveryMemberAndTheSupplierOnce(): void
    {
        $supplierUser = $this->customer();
        $this->supplier->setUser($supplierUser);
        $campaign = $this->campaign(quantity: 8);
        $early = $this->member($campaign, quantity: 8);
        $withdrawn = $this->member($campaign, quantity: 4, status: ParticipationStatus::Annullata);
        $buyer = $this->customer();

        $this->service->confirm($this->paid($campaign, $buyer, quantity: 2, amount: 360, pi: 'pi_n2'));

        foreach ([$early->getUser(), $buyer, $supplierUser] as $recipient) {
            $found = $this->notificationsOf($recipient, NotificationType::MoqRaggiunto);
            self::assertCount(1, $found, 'Ogni destinatario riceve un solo avviso.');
            self::assertSame('Minimo raggiunto', $found[0]->getTitle());
        }
        self::assertStringContainsString('per "Prodotto soglie ', $this->notificationsOf($early->getUser(), NotificationType::MoqRaggiunto)[0]->getMessage());
        self::assertStringContainsString('Preparati a evadere', $this->notificationsOf($supplierUser, NotificationType::MoqRaggiunto)[0]->getMessage());
        self::assertSame([], $this->notificationsOf($withdrawn->getUser(), NotificationType::MoqRaggiunto), 'Chi si è ritirato non riceve nulla.');
        self::assertSame([], $this->notificationsOf($early->getUser(), NotificationType::NuovoScaglione), 'Il primo scaglione è il minimo, non un prezzo che scende.');
    }

    public function testJoiningAGroupThatIsAlreadyActiveDoesNotRepeatTheMinimumNotice(): void
    {
        $campaign = $this->campaign(quantity: 12, status: CampaignStatus::Riuscita);
        $early = $this->member($campaign, quantity: 12, status: ParticipationStatus::Confermata);
        $buyer = $this->customer();

        $this->service->confirm($this->paid($campaign, $buyer, quantity: 2, amount: 360, pi: 'pi_n3'));

        self::assertSame([], $this->notificationsOf($early->getUser(), NotificationType::MoqRaggiunto));
        self::assertSame([], $this->notificationsOf($buyer, NotificationType::MoqRaggiunto));
        self::assertCount(1, $this->notificationsOf($buyer, NotificationType::OrdineConfermato));
    }

    public function testANewPriceTierTellsEveryLiveMemberOnlyTheFirstTime(): void
    {
        $campaign = $this->campaign(quantity: 18, status: CampaignStatus::Riuscita);
        $early = $this->member($campaign, quantity: 18, status: ParticipationStatus::Confermata);
        $buyer = $this->customer();
        $third = $this->customer();

        $this->service->confirm($this->paid($campaign, $buyer, quantity: 2, amount: 320, pi: 'pi_n4'));

        foreach ([$early->getUser(), $buyer] as $recipient) {
            $found = $this->notificationsOf($recipient, NotificationType::NuovoScaglione);
            self::assertCount(1, $found);
            self::assertSame('Il prezzo è sceso', $found[0]->getTitle());
            self::assertStringContainsString('ora costa 8,00 €', $found[0]->getMessage());
            self::assertStringContainsString('20 unità', $found[0]->getMessage());
        }
        self::assertSame([], $this->notificationsOf($early->getUser(), NotificationType::MoqRaggiunto));

        // Un'altra adesione che non cambia scaglione: nessun secondo avviso.
        $this->service->confirm($this->paid($campaign, $third, quantity: 1, amount: 160, pi: 'pi_n5'));
        self::assertCount(1, $this->notificationsOf($early->getUser(), NotificationType::NuovoScaglione));
        self::assertSame([], $this->notificationsOf($third, NotificationType::NuovoScaglione));
    }

    public function testTheSameEventDoesNotNotifyTwice(): void
    {
        $campaign = $this->campaign(quantity: 8);
        $early = $this->member($campaign, quantity: 8);
        $buyer = $this->customer();
        $paid = $this->paid($campaign, $buyer, quantity: 2, amount: 360, pi: 'pi_n6');

        $this->service->confirm($paid);
        $this->service->confirm($paid);

        self::assertCount(1, $this->notificationsOf($early->getUser(), NotificationType::MoqRaggiunto));
        self::assertCount(1, $this->notificationsOf($buyer, NotificationType::OrdineConfermato));
    }

    private function campaign(int $quantity, CampaignStatus $status = CampaignStatus::InCorso): Campaign
    {
        $product = (new Product($this->supplier, 'Prodotto soglie '.++$this->counter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+14 days'), 1200, 900);
        $campaign->setPickupPoint($this->pickupPoint)->setCurrentQuantity($quantity)->setStatus($status);
        $campaign->addPriceTier(new PriceTier($campaign, 10, 900));
        $campaign->addPriceTier(new PriceTier($campaign, 20, 800));
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function customer(): User
    {
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@threshold-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Adesione già presente (la quantità del gruppo la decide campaign()). */
    private function member(Campaign $campaign, int $quantity, ParticipationStatus $status = ParticipationStatus::Prenotata): Participation
    {
        $participation = (new Participation($campaign, $this->customer(), $quantity))->setDepositCents(100)->setStatus($status);
        $this->em->persist($participation);
        $this->em->flush();

        return $participation;
    }

    private function paid(Campaign $campaign, User $user, int $quantity, int $amount, string $pi): PaidDeposit
    {
        return new PaidDeposit($pi, $amount, 'pm_test', 'succeeded', [
            'tipo' => 'acconto',
            'colletta_id' => $campaign->getId()->toRfc4122(),
            'quantita' => (string) $quantity,
            'utente_id' => $user->getId()->toRfc4122(),
            'delivery_type' => 'ritiro_sede',
            'id_sede' => $this->pickupPoint->getId()->toRfc4122(),
            'indirizzo' => '',
        ]);
    }

    /**
     * @return list<Notification>
     */
    private function notificationsOf(User $user, NotificationType $type): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['user' => $user->getId(), 'type' => $type]);
    }
}
