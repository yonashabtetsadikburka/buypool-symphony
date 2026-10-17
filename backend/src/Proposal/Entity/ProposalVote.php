<?php

namespace App\Proposal\Entity;

use App\Identity\Entity\User;
use App\Proposal\Enum\VoteValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'proposal_votes')]
#[ORM\UniqueConstraint(name: 'uniq_proposal_vote_voter', columns: ['proposal_id', 'voter_id'])]
class ProposalVote
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Proposal::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Proposal $proposal;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $voter;

    #[ORM\Column(length: 10, enumType: VoteValue::class)]
    private VoteValue $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Proposal $proposal, User $voter, VoteValue $value)
    {
        $this->id = Uuid::v7();
        $this->proposal = $proposal;
        $this->voter = $voter;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProposal(): Proposal
    {
        return $this->proposal;
    }

    public function getVoter(): User
    {
        return $this->voter;
    }

    public function getValue(): VoteValue
    {
        return $this->value;
    }

    public function setValue(VoteValue $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
