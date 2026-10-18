<?php

namespace App\Tests\Integration\Campaign;

use App\Campaign\Application\CampaignTransitioner;
use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Campaign\Event\CampaignStatusChanged;
use App\Campaign\Exception\CampaignTransitionNotAllowedException;
use App\Identity\Entity\User;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\CampaignWorkflowFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CampaignTransitionIntegrationTest extends DatabaseTestCase
{
    public function testReachingTheMinimumOrderQuantityMovesAPersistedCampaignToSuccessAndEmitsCampaignStatusChanged(): void
    {
        $campaign = $this->persistCampaign(CampaignWorkflowFactory::MOQ);
        $actor = $this->persistActor();
        $this->em->flush();

        $events = [];
        $this->collectStatusChangedEvents($events);

        $transitioner = $this->transitioner();
        $transitioner->apply($campaign, CampaignTransition::ReachMoq, $actor);

        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Campaign::class)->find($campaign->getId());
        self::assertInstanceOf(Campaign::class, $reloaded);
        self::assertSame(CampaignStatus::Riuscita, $reloaded->getStatus());
        self::assertNotNull($reloaded->getStatusUpdatedAt());

        self::assertCount(1, $events);
        self::assertSame($campaign->getId()->toRfc4122(), $events[0]->campaignId);
        self::assertSame(CampaignStatus::InCorso, $events[0]->from);
        self::assertSame(CampaignStatus::Riuscita, $events[0]->to);
        self::assertSame($actor->getId()->toRfc4122(), $events[0]->actorId);
    }

    public function testDeliveryIsPersistedOnlyWhenTheSupplierOrderIsFulfilled(): void
    {
        $campaign = $this->persistCampaign(CampaignWorkflowFactory::MOQ);
        $campaign->setStatus(CampaignStatus::OrdineFornitore);

        $order = new SupplierOrder($campaign, $campaign->getProduct()->getSupplier(), '2026-000001', 2026, CampaignWorkflowFactory::MOQ, 9000);
        $this->em->persist($order);
        $this->em->flush();

        $events = [];
        $this->collectStatusChangedEvents($events);

        $transitioner = $this->transitioner();

        try {
            $transitioner->apply($campaign, CampaignTransition::Deliver);
            self::fail('A campaign must not be delivered while its supplier order is still open.');
        } catch (CampaignTransitionNotAllowedException) {
        }

        self::assertSame(CampaignStatus::OrdineFornitore, $campaign->getStatus());

        $order->setStatus(SupplierOrderStatus::Evaso);
        $this->em->flush();

        $transitioner->apply($campaign, CampaignTransition::Deliver);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Campaign::class)->find($campaign->getId());
        self::assertInstanceOf(Campaign::class, $reloaded);
        self::assertSame(CampaignStatus::Consegnata, $reloaded->getStatus());

        self::assertCount(1, $events);
        self::assertSame(CampaignStatus::OrdineFornitore, $events[0]->from);
        self::assertSame(CampaignStatus::Consegnata, $events[0]->to);
    }

    private function persistCampaign(int $quantity): Campaign
    {
        $supplier = new Supplier('Example Supplier');
        $this->em->persist($supplier);

        $product = new Product($supplier, 'Example Product', 1000);
        $this->em->persist($product);

        $campaign = new Campaign($product, CampaignWorkflowFactory::MOQ, new \DateTimeImmutable('+30 days'), 1000, 900);
        $campaign->setCurrentQuantity($quantity);
        $this->em->persist($campaign);

        return $campaign;
    }

    private function persistActor(): User
    {
        $actor = new User('Admin', 'admin@example.com');
        $this->em->persist($actor);

        return $actor;
    }

    private function transitioner(): CampaignTransitioner
    {
        $transitioner = self::getContainer()->get(CampaignTransitioner::class);
        self::assertInstanceOf(CampaignTransitioner::class, $transitioner);

        return $transitioner;
    }

    /**
     * @param list<CampaignStatusChanged> $events
     */
    private function collectStatusChangedEvents(array &$events): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->addListener(
            CampaignStatusChanged::class,
            function (CampaignStatusChanged $event) use (&$events): void {
                $events[] = $event;
            },
        );
    }
}
