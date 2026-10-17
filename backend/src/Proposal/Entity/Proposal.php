<?php

namespace App\Proposal\Entity;

use App\Identity\Entity\User;
use App\Proposal\Enum\ProposalStatus;
use App\Proposal\Repository\ProposalRepository;
use App\Supplier\Entity\Supplier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProposalRepository::class)]
#[ORM\Table(name: 'proposals')]
#[ORM\Index(name: 'idx_proposals_status', columns: ['status'])]
class Proposal
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $proposer;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supplier $suggestedSupplier = null;

    #[ORM\Column(length: 20, enumType: ProposalStatus::class, options: ['default' => 'in_attesa'])]
    private ProposalStatus $status = ProposalStatus::InAttesa;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $votesCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $requestedMoq = null;

    #[ORM\Column(nullable: true)]
    private ?int $basePriceCents = null;

    #[ORM\Column(nullable: true)]
    private ?int $currentPriceCents = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $deliveryTime = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $referenceUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /** @var Collection<int, ProposalTier> */
    #[ORM\OneToMany(targetEntity: ProposalTier::class, mappedBy: 'proposal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tiers;

    public function __construct(User $proposer, string $title)
    {
        $title = trim($title);
        if ('' === $title) {
            throw new \InvalidArgumentException('Proposal title cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->proposer = $proposer;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->tiers = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProposer(): User
    {
        return $this->proposer;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $title = trim($title);
        if ('' === $title) {
            throw new \InvalidArgumentException('Proposal title cannot be empty.');
        }

        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getSuggestedSupplier(): ?Supplier
    {
        return $this->suggestedSupplier;
    }

    public function setSuggestedSupplier(?Supplier $suggestedSupplier): self
    {
        $this->suggestedSupplier = $suggestedSupplier;

        return $this;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function setStatus(ProposalStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getHandledBy(): ?User
    {
        return $this->handledBy;
    }

    public function setHandledBy(?User $handledBy): self
    {
        $this->handledBy = $handledBy;

        return $this;
    }

    public function getVotesCount(): int
    {
        return $this->votesCount;
    }

    public function setVotesCount(int $votesCount): self
    {
        if ($votesCount < 0) {
            throw new \InvalidArgumentException('Votes count cannot be negative.');
        }

        $this->votesCount = $votesCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getHandledAt(): ?\DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function setHandledAt(?\DateTimeImmutable $handledAt): self
    {
        $this->handledAt = $handledAt;

        return $this;
    }

    public function getRequestedMoq(): ?int
    {
        return $this->requestedMoq;
    }

    public function setRequestedMoq(?int $requestedMoq): self
    {
        if (null !== $requestedMoq && $requestedMoq <= 0) {
            throw new \InvalidArgumentException('Requested MOQ must be greater than zero.');
        }

        $this->requestedMoq = $requestedMoq;

        return $this;
    }

    public function getBasePriceCents(): ?int
    {
        return $this->basePriceCents;
    }

    public function setBasePriceCents(?int $basePriceCents): self
    {
        if (null !== $basePriceCents && $basePriceCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->basePriceCents = $basePriceCents;

        return $this;
    }

    public function getCurrentPriceCents(): ?int
    {
        return $this->currentPriceCents;
    }

    public function setCurrentPriceCents(?int $currentPriceCents): self
    {
        if (null !== $currentPriceCents && $currentPriceCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->currentPriceCents = $currentPriceCents;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDeliveryTime(): ?string
    {
        return $this->deliveryTime;
    }

    public function setDeliveryTime(?string $deliveryTime): self
    {
        $this->deliveryTime = $deliveryTime;

        return $this;
    }

    public function getReferenceUrl(): ?string
    {
        return $this->referenceUrl;
    }

    public function setReferenceUrl(?string $referenceUrl): self
    {
        $this->referenceUrl = $referenceUrl;

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $this->photoPath = $photoPath;

        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;

        return $this;
    }

    /**
     * @return Collection<int, ProposalTier>
     */
    public function getTiers(): Collection
    {
        return $this->tiers;
    }

    public function addTier(ProposalTier $tier): self
    {
        if (!$this->tiers->contains($tier)) {
            $this->tiers->add($tier);
        }

        return $this;
    }
}
