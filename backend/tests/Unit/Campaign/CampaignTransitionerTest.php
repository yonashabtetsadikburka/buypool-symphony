<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Application\CampaignTransitioner;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Campaign\Event\CampaignStatusChanged;
use App\Campaign\Exception\CampaignTransitionNotAllowedException;
use App\Identity\Entity\User;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use App\Tests\Support\CampaignWorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CampaignTransitionerTest extends TestCase
{
    public function testAnAllowedTransitionMovesTheCampaignAndDispatchesCampaignStatusChanged(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ, 30);
        $actor = new User('Admin', 'admin@example.com');

        $events = [];
        $transitioner = $this->transitioner($events);
        $transitioner->apply($campaign, CampaignTransition::ReachMoq, $actor);

        self::assertSame(CampaignStatus::Riuscita, $campaign->getStatus());
        self::assertNotNull($campaign->getStatusUpdatedAt());

        self::assertCount(1, $events);
        self::assertSame($campaign->getId()->toRfc4122(), $events[0]->campaignId);
        self::assertSame(CampaignStatus::InCorso, $events[0]->from);
        self::assertSame(CampaignStatus::Riuscita, $events[0]->to);
        self::assertSame($actor->getId()->toRfc4122(), $events[0]->actorId);
    }

    public function testAForbiddenTransitionIsRejectedAndTheCampaignKeepsItsStatus(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::InCorso, CampaignWorkflowFactory::MOQ - 1, 30);

        $events = [];
        $transitioner = $this->transitioner($events);

        try {
            $transitioner->apply($campaign, CampaignTransition::ReachMoq);
            self::fail('A campaign below the minimum order quantity must not become successful.');
        } catch (CampaignTransitionNotAllowedException $exception) {
            self::assertStringContainsString('reach_moq', $exception->getMessage());
        }

        self::assertSame(CampaignStatus::InCorso, $campaign->getStatus());
        self::assertNull($campaign->getStatusUpdatedAt());
        self::assertCount(0, $events);
    }

    public function testDeliveryRequiresAFulfilledSupplierOrder(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, CampaignWorkflowFactory::MOQ, -1);

        $openOrderRepository = $this->repositoryReturning(SupplierOrderStatus::Inviato);
        $events = [];
        $transitioner = $this->transitioner($events, $openOrderRepository);

        $this->expectException(CampaignTransitionNotAllowedException::class);
        $transitioner->apply($campaign, CampaignTransition::Deliver);
    }

    public function testDeliverySucceedsOnceTheSupplierOrderIsFulfilled(): void
    {
        $campaign = CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, CampaignWorkflowFactory::MOQ, -1);

        $fulfilledOrderRepository = $this->repositoryReturning(SupplierOrderStatus::Evaso);
        $events = [];
        $transitioner = $this->transitioner($events, $fulfilledOrderRepository);
        $transitioner->apply($campaign, CampaignTransition::Deliver);

        self::assertSame(CampaignStatus::Consegnata, $campaign->getStatus());
        self::assertCount(1, $events);
        self::assertSame(CampaignStatus::OrdineFornitore, $events[0]->from);
        self::assertSame(CampaignStatus::Consegnata, $events[0]->to);
        self::assertNull($events[0]->actorId);
    }

    /**
     * @param list<CampaignStatusChanged> $events
     */
    private function transitioner(array &$events, ?SupplierOrderRepository $repository = null): CampaignTransitioner
    {
        $repository ??= $this->createStub(SupplierOrderRepository::class);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            CampaignStatusChanged::class,
            function (CampaignStatusChanged $event) use (&$events): void {
                $events[] = $event;
            },
        );

        return new CampaignTransitioner(CampaignWorkflowFactory::createRegistry($repository), $dispatcher);
    }

    private function repositoryReturning(SupplierOrderStatus $status): SupplierOrderRepository
    {
        $order = CampaignWorkflowFactory::createSupplierOrder(
            CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, CampaignWorkflowFactory::MOQ, -1),
            $status,
        );

        $repository = $this->createStub(SupplierOrderRepository::class);
        $repository->method('findOneBy')->willReturn($order);

        return $repository;
    }
}
