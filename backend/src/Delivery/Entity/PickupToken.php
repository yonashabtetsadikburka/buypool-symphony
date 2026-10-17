<?php

namespace App\Delivery\Entity;

use App\Delivery\Enum\PickupTokenStatus;
use App\Delivery\Repository\PickupTokenRepository;
use App\Participation\Entity\Participation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PickupTokenRepository::class)]
#[ORM\Table(name: 'pickup_tokens')]
class PickupToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Many-to-one on purpose: the legacy schema has no UNIQUE on the participation and the
     * domain ER models one participation -> many pickup tokens (partial pickups via assignedQuantity).
     */
    #[ORM\ManyToOne(targetEntity: Participation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Participation $participation;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private int $assignedQuantity;

    #[ORM\Column(length: 20, enumType: PickupTokenStatus::class, options: ['default' => 'generato'])]
    private PickupTokenStatus $status = PickupTokenStatus::Generato;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scannedAt = null;

    public function __construct(Participation $participation, string $tokenHash, int $assignedQuantity)
    {
        $tokenHash = trim($tokenHash);
        if ('' === $tokenHash) {
            throw new \InvalidArgumentException('Token hash cannot be empty.');
        }

        if ($assignedQuantity <= 0) {
            throw new \InvalidArgumentException('Assigned quantity must be greater than zero.');
        }

        $this->id = Uuid::v7();
        $this->participation = $participation;
        $this->tokenHash = $tokenHash;
        $this->assignedQuantity = $assignedQuantity;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParticipation(): Participation
    {
        return $this->participation;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Rotation on display: each display issues a brand new token and stores only its hash.
     */
    public function rotateTokenHash(string $tokenHash): self
    {
        $tokenHash = trim($tokenHash);
        if ('' === $tokenHash) {
            throw new \InvalidArgumentException('Token hash cannot be empty.');
        }

        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getAssignedQuantity(): int
    {
        return $this->assignedQuantity;
    }

    public function getStatus(): PickupTokenStatus
    {
        return $this->status;
    }

    public function markScanned(): self
    {
        $this->status = PickupTokenStatus::Scansionato;
        $this->scannedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getScannedAt(): ?\DateTimeImmutable
    {
        return $this->scannedAt;
    }
}
