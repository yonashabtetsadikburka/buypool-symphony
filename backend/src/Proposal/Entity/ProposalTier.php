<?php

namespace App\Proposal\Entity;

use App\Proposal\Repository\ProposalTierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProposalTierRepository::class)]
#[ORM\Table(name: 'proposal_tiers')]
#[ORM\UniqueConstraint(name: 'uniq_proposal_tier_threshold', columns: ['proposal_id', 'threshold'])]
class ProposalTier
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Proposal::class, inversedBy: 'tiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Proposal $proposal;

    #[ORM\Column]
    private int $threshold;

    #[ORM\Column]
    private int $priceCents;

    public function __construct(Proposal $proposal, int $threshold, int $priceCents)
    {
        if ($threshold <= 0) {
            throw new \InvalidArgumentException('Tier threshold must be greater than zero.');
        }

        if ($priceCents <= 0) {
            throw new \InvalidArgumentException('Tier price must be greater than zero.');
        }

        $this->id = Uuid::v7();
        $this->proposal = $proposal;
        $this->threshold = $threshold;
        $this->priceCents = $priceCents;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProposal(): Proposal
    {
        return $this->proposal;
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
