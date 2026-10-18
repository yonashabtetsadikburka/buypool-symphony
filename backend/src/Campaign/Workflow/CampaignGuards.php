<?php

namespace App\Campaign\Workflow;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignTransition;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class CampaignGuards implements EventSubscriberInterface
{
    public function __construct(private readonly SupplierOrderRepository $supplierOrderRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.campaign.guard' => 'onGuard'];
    }

    public function onGuard(GuardEvent $event): void
    {
        if (!$event->getSubject() instanceof Campaign) {
            return;
        }

        match ($event->getTransition()->getName()) {
            CampaignTransition::ReachMoq->value => $this->guardReachMoq($event),
            CampaignTransition::FailOnDeadline->value => $this->guardFailOnDeadline($event),
            CampaignTransition::LostMoq->value => $this->guardLostMoq($event),
            CampaignTransition::Deliver->value => $this->guardDeliver($event),
            default => null,
        };
    }

    public static function isFulfilled(?SupplierOrder $order): bool
    {
        return $order instanceof SupplierOrder && SupplierOrderStatus::Evaso === $order->getStatus();
    }

    private function guardReachMoq(GuardEvent $event): void
    {
        $campaign = $event->getSubject();

        $event->setBlocked(
            $campaign->getCurrentQuantity() < $campaign->getMoq(),
            'The minimum order quantity has not been reached yet.',
        );
    }

    private function guardFailOnDeadline(GuardEvent $event): void
    {
        $campaign = $event->getSubject();

        $event->setBlocked(
            $campaign->getCurrentQuantity() >= $campaign->getMoq()
                || $campaign->getDeadline() > new \DateTimeImmutable(),
            'A campaign only fails once the deadline has passed and the minimum order quantity was not reached.',
        );
    }

    private function guardLostMoq(GuardEvent $event): void
    {
        $campaign = $event->getSubject();

        $event->setBlocked(
            $campaign->getCurrentQuantity() >= $campaign->getMoq()
                || $campaign->getDeadline() <= new \DateTimeImmutable(),
            'The minimum order quantity can only be lost while the campaign is still open.',
        );
    }

    private function guardDeliver(GuardEvent $event): void
    {
        $campaign = $event->getSubject();
        $order = $this->supplierOrderRepository->findOneBy(['campaign' => $campaign]);

        $event->setBlocked(
            !self::isFulfilled($order),
            'The supplier order must exist and be fulfilled before the campaign can be marked as delivered.',
        );
    }
}
