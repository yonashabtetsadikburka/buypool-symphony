<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Campaign\Workflow\CampaignGuards;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use App\Tests\Support\CampaignWorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class CampaignGuardsTest extends TestCase
{
    public function testMinimumOrderQuantityMustBeReachedBeforeASuccessfulCampaign(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ, 30);
        $event = $this->guardFor($campaign, CampaignTransition::ReachMoq);

        self::assertFalse($event->isBlocked());

        $campaign->setCurrentQuantity(CampaignWorkflowFactory::MOQ - 1);
        $event = $this->guardFor($campaign, CampaignTransition::ReachMoq);

        self::assertTrue($event->isBlocked());
        $blockers = array_values(iterator_to_array($event->getTransitionBlockerList()));
        self::assertNotEmpty($blockers);
        self::assertStringContainsString('minimum order quantity', (string) $blockers[0]->getMessage());
    }

    public function testCampaignFailsOnlyAfterTheDeadlineAndBelowTheMinimumOrderQuantity(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ - 1, -1);
        $event = $this->guardFor($campaign, CampaignTransition::FailOnDeadline);
        self::assertFalse($event->isBlocked());

        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ, -1);
        $event = $this->guardFor($campaign, CampaignTransition::FailOnDeadline);
        self::assertTrue($event->isBlocked());

        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ - 1, 1);
        $event = $this->guardFor($campaign, CampaignTransition::FailOnDeadline);
        self::assertTrue($event->isBlocked());
    }

    public function testMinimumOrderQuantityIsOnlyLostWhileTheCampaignIsStillOpen(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::Riuscita, CampaignWorkflowFactory::MOQ - 1, 30);
        $event = $this->guardFor($campaign, CampaignTransition::LostMoq);
        self::assertFalse($event->isBlocked());

        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::Riuscita, CampaignWorkflowFactory::MOQ, 30);
        $event = $this->guardFor($campaign, CampaignTransition::LostMoq);
        self::assertTrue($event->isBlocked());

        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::Riuscita, CampaignWorkflowFactory::MOQ - 1, -1);
        $event = $this->guardFor($campaign, CampaignTransition::LostMoq);
        self::assertTrue($event->isBlocked());
    }

    public function testDeliveryRequiresAnExistingFulfilledSupplierOrder(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, CampaignWorkflowFactory::MOQ, -1);

        $event = $this->guardFor($campaign, CampaignTransition::Deliver, $this->orderReturningRepository(null));
        self::assertTrue($event->isBlocked());

        $event = $this->guardFor($campaign, CampaignTransition::Deliver, $this->orderReturningRepository(SupplierOrderStatus::Inviato));
        self::assertTrue($event->isBlocked());

        $event = $this->guardFor($campaign, CampaignTransition::Deliver, $this->orderReturningRepository(SupplierOrderStatus::Evaso));
        self::assertFalse($event->isBlocked());
    }

    public function testGuardsIgnoreSubjectsThatAreNotCampaigns(): void
    {
        $repository = $this->createStub(SupplierOrderRepository::class);
        $guards = new CampaignGuards($repository);
        $event = new GuardEvent(new \stdClass(), new Marking(), new Transition('reach_moq', ['in_corso'], 'riuscita'));

        $guards->onGuard($event);

        self::assertFalse($event->isBlocked());
    }

    private function guardFor(
        Campaign $campaign,
        CampaignTransition $transition,
        ?SupplierOrderRepository $repository = null,
    ): GuardEvent {
        $repository ??= $this->createStub(SupplierOrderRepository::class);
        $guards = new CampaignGuards($repository);
        $event = new GuardEvent($campaign, new Marking(), new Transition($transition->value, ['*'], 'placeholder'));

        $guards->onGuard($event);

        return $event;
    }

    private function orderReturningRepository(?SupplierOrderStatus $status): SupplierOrderRepository
    {
        $order = null === $status
            ? null
            : CampaignWorkflowFactory::createSupplierOrder(
                CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, CampaignWorkflowFactory::MOQ, -1),
                $status,
            );

        $repository = $this->createStub(SupplierOrderRepository::class);
        $repository->method('findOneBy')->willReturn($order);

        return $repository;
    }
}
