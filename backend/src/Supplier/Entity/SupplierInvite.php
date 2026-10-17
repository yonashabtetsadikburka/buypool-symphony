<?php

namespace App\Supplier\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_invites')]
class SupplierInvite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 255, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Supplier $supplier, string $email, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $email = trim($email);
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $tokenHash = trim($tokenHash);
        if ('' === $tokenHash) {
            throw new \InvalidArgumentException('Token hash cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->supplier = $supplier;
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): self
    {
        $this->usedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
