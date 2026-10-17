<?php

namespace App\Supplier\Entity;

use App\Identity\Entity\User;
use App\Supplier\Repository\SupplierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'suppliers')]
class Supplier
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isPublic = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $partneredAt;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $websiteUrl = null;

    public function __construct(string $name)
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Supplier name cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->name = $name;
        $this->partneredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Supplier name cannot be empty.');
        }

        $this->name = $name;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

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

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): self
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    public function getPartneredAt(): \DateTimeImmutable
    {
        return $this->partneredAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): self
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): self
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }
}
