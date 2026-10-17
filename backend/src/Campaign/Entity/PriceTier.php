<?php

namespace App\Campaign\Entity;

use App\Campaign\Repository\PriceTierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PriceTierRepository::class)]
#[ORM\Table(name: 'price_tiers')]
#[ORM\UniqueConstraint(name: 'uniq_price_tier_threshold', columns: ['campaign_id', 'threshold'])]
class PriceTier
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'priceTiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column]
    private int $threshold;

    #[ORM\Column]
    private int $priceCents;

    public function __construct(Campaign $campaign, int $threshold, int $priceCents)
    {
        if ($threshold < 1) {
            throw new \InvalidArgumentException('Tier threshold must be at least 1.');
        }

        if ($priceCents < 0) {
            throw new \InvalidArgumentException('Tier price cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->campaign = $campaign;
        $this->threshold = $threshold;
        $this->priceCents = $priceCents;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }
}
