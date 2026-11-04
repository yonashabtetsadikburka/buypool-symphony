<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Enum\CampaignStatus;
use App\Participation\Enum\ParticipationStatus;
use App\Tests\Support\FakeStripeGateway;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Il comando pianificato che sostituisce i cron del legacy: chiude i gruppi scaduti e annulla i saldi non pagati.
 */
final class RunScheduledCommandTest extends KernelTestCase
{
    use GroupFixtures;

    private EntityManagerInterface $em;
    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testTheCommandClosesExpiredGroupsAndLeavesOpenOnesAlone(): void
    {
        $expired = $this->group($this->em, quantity: 22);
        $member = $this->member($this->em, $expired, ParticipationStatus::Confermata);
        $open = $this->group($this->em, quantity: 22, deadline: '+2 days');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString($expired->getId()->toRfc4122(), $tester->getDisplay());
        self::assertStringContainsString('chiuso', $tester->getDisplay());
        self::assertStringNotContainsString($open->getId()->toRfc4122(), $tester->getDisplay());
        self::assertSame(CampaignStatus::OrdinePronto, $expired->getStatus());
        self::assertSame(ParticipationStatus::Pagata, $member->getStatus());
        self::assertSame(CampaignStatus::InCorso, $open->getStatus());
    }

    public function testRunningItAgainDoesNothingNew(): void
    {
        $expired = $this->group($this->em, quantity: 22);
        $this->member($this->em, $expired, ParticipationStatus::Confermata);
        $this->tester()->execute([]);
        $charges = \count($this->stripe->charges);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertCount($charges, $this->stripe->charges);
        self::assertStringContainsString('Nessun gruppo da chiudere', $tester->getDisplay());
    }

    public function testAFailedRefundMakesTheCommandExitWithAnErrorSoItIsNoticed(): void
    {
        $expired = $this->group($this->em, quantity: 6);
        $this->member($this->em, $expired, ParticipationStatus::Prenotata);
        $this->stripe->failRefunds = true;

        $exit = $this->tester()->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($expired->getClosedAt());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:campaigns:run-scheduled'));
    }
}
