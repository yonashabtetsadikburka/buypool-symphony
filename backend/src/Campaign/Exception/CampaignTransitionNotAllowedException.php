<?php

namespace App\Campaign\Exception;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignTransition;

final class CampaignTransitionNotAllowedException extends \RuntimeException
{
    public static function forCampaign(Campaign $campaign, CampaignTransition $transition): self
    {
        return new self(\sprintf(
            'Campaign "%s" cannot perform transition "%s" from status "%s".',
            $campaign->getId()->toRfc4122(),
            $transition->value,
            $campaign->getStatus()->value,
        ));
    }
}
