<?php

namespace App\Participation\Entity;

use App\Campaign\Entity\Campaign;
use App\Identity\Entity\User;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ParticipationRepository::class)]
#[ORM\Table(name: 'participations')]
#[ORM\UniqueConstraint(name: 'uniq_participation_campaign_user', columns: ['campaign_id', 'user_id'])]
#[ORM\Index(name: 'idx_participations_campaign', columns: ['campaign_id'])]
class Participation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(options: ['default' => 0])]
    private int $depositCents = 0;

    #[ORM\Column(nullable: true)]
    private ?int $balanceCents = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $commissionCents = 0;

    #[ORM\Column(length: 20, enumType: ParticipationStatus::class, options: ['default' => 'prenotata'])]
    private ParticipationStatus $status = ParticipationStatus::Prenotata;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Campaign $campaign, User $user, int $quantity)
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $this->id = Uuid::v7();
        $this->campaign = $campaign;
        $this->user = $user;
        $this->quantity = $quantity;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $this->quantity = $quantity;

        return $this;
    }

    public function getDepositCents(): int
    {
        return $this->depositCents;
    }

    public function setDepositCents(int $depositCents): self
    {
        if ($depositCents < 0) {
            throw new \InvalidArgumentException('Deposit cannot be negative.');
        }

        $this->depositCents = $depositCents;

        return $this;
    }

    public function getBalanceCents(): ?int
    {
        return $this->balanceCents;
    }

    public function setBalanceCents(?int $balanceCents): self
    {
        if (null !== $balanceCents && $balanceCents < 0) {
            throw new \InvalidArgumentException('Balance cannot be negative.');
        }

        $this->balanceCents = $balanceCents;

        return $this;
    }

    public function getCommissionCents(): int
    {
        return $this->commissionCents;
    }

    public function setCommissionCents(int $commissionCents): self
    {
        if ($commissionCents < 0) {
            throw new \InvalidArgumentException('Commission cannot be negative.');
        }

        $this->commissionCents = $commissionCents;

        return $this;
    }

    public function getStatus(): ParticipationStatus
    {
        return $this->status;
    }

    public function setStatus(ParticipationStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }
}
