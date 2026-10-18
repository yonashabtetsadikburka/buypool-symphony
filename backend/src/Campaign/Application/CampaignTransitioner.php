<?php

namespace App\Campaign\Application;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignTransition;
use App\Campaign\Event\CampaignStatusChanged;
use App\Campaign\Exception\CampaignTransitionNotAllowedException;
use App\Identity\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Registry;

final class CampaignTransitioner
{
    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function apply(Campaign $campaign, CampaignTransition $transition, ?User $actor = null): void
    {
        $workflow = $this->workflowRegistry->get($campaign, 'campaign');

        if (!$workflow->can($campaign, $transition->value)) {
            throw CampaignTransitionNotAllowedException::forCampaign($campaign, $transition);
        }

        $from = $campaign->getStatus();

        $workflow->apply($campaign, $transition->value);

        $campaign->setStatusUpdatedAt(new \DateTimeImmutable());

        $this->eventDispatcher->dispatch(new CampaignStatusChanged(
            campaignId: $campaign->getId()->toRfc4122(),
            from: $from,
            to: $campaign->getStatus(),
            actorId: $actor?->getId()->toRfc4122(),
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
