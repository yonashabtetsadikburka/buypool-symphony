<?php

namespace App\Campaign\Entity;

use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Repository\CampaignRepository;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaigns')]
#[ORM\Index(name: 'idx_campaigns_status', columns: ['status', 'deadline'])]
class Campaign
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $openedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $referrer = null;

    #[ORM\ManyToOne(targetEntity: PickupPoint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PickupPoint $pickupPoint = null;

    #[ORM\Column]
    private int $moq;

    #[ORM\Column(options: ['default' => 0])]
    private int $currentQuantity = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $deadline;

    #[ORM\Column(length: 30, enumType: CampaignStatus::class, options: ['default' => 'in_corso'])]
    private CampaignStatus $status = CampaignStatus::InCorso;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statusUpdatedAt = null;

    #[ORM\Column]
    private int $basePriceCents;

    #[ORM\Column]
    private int $currentPriceCents;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '10.00'])]
    private string $commissionRate = '10.00';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, PriceTier> */
    #[ORM\OneToMany(targetEntity: PriceTier::class, mappedBy: 'campaign', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $priceTiers;

    public function __construct(
        Product $product,
        int $moq,
        \DateTimeImmutable $deadline,
        int $basePriceCents,
        int $currentPriceCents,
    ) {
        if ($moq < 1) {
            throw new \InvalidArgumentException('MOQ must be at least 1.');
        }

        if ($basePriceCents < 0 || $currentPriceCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->product = $product;
        $this->moq = $moq;
        $this->startsAt = new \DateTimeImmutable();
        $this->deadline = $deadline;
        $this->basePriceCents = $basePriceCents;
        $this->currentPriceCents = $currentPriceCents;
        $this->priceTiers = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    public function setOpenedBy(?User $openedBy): self
    {
        $this->openedBy = $openedBy;

        return $this;
    }

    public function getReferrer(): ?User
    {
        return $this->referrer;
    }

    public function setReferrer(?User $referrer): self
    {
        $this->referrer = $referrer;

        return $this;
    }

    public function getPickupPoint(): ?PickupPoint
    {
        return $this->pickupPoint;
    }

    public function setPickupPoint(?PickupPoint $pickupPoint): self
    {
        $this->pickupPoint = $pickupPoint;

        return $this;
    }

    public function getMoq(): int
    {
        return $this->moq;
    }

    public function getCurrentQuantity(): int
    {
        return $this->currentQuantity;
    }

    public function setCurrentQuantity(int $currentQuantity): self
    {
        if ($currentQuantity < 0) {
            throw new \InvalidArgumentException('Current quantity cannot be negative.');
        }

        $this->currentQuantity = $currentQuantity;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getDeadline(): \DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(\DateTimeImmutable $deadline): self
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getStatus(): CampaignStatus
    {
        return $this->status;
    }

    public function setStatus(CampaignStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(?\DateTimeImmutable $statusUpdatedAt): self
    {
        $this->statusUpdatedAt = $statusUpdatedAt;

        return $this;
    }

    public function getBasePriceCents(): int
    {
        return $this->basePriceCents;
    }

    public function getCurrentPriceCents(): int
    {
        return $this->currentPriceCents;
    }

    public function setCurrentPriceCents(int $currentPriceCents): self
    {
        if ($currentPriceCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->currentPriceCents = $currentPriceCents;

        return $this;
    }

    public function getCommissionRate(): string
    {
        return $this->commissionRate;
    }

    public function setCommissionRate(string $commissionRate): self
    {
        $this->commissionRate = $commissionRate;

        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): self
    {
        $this->confirmedBy = $confirmedBy;

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    /**
     * @return Collection<int, PriceTier>
     */
    public function getPriceTiers(): Collection
    {
        return $this->priceTiers;
    }

    public function addPriceTier(PriceTier $priceTier): self
    {
        if (!$this->priceTiers->contains($priceTier)) {
            $this->priceTiers->add($priceTier);
        }

        return $this;
    }
}
