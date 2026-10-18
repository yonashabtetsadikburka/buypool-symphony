<?php

namespace App\Campaign\Event;

use App\Campaign\Enum\CampaignStatus;

final readonly class CampaignStatusChanged
{
    public function __construct(
        public string $campaignId,
        public CampaignStatus $from,
        public CampaignStatus $to,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
