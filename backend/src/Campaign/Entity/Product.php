<?php

namespace App\Campaign\Entity;

use App\Campaign\Enum\ProductStatus;
use App\Campaign\Repository\ProductRepository;
use App\Proposal\Entity\Proposal;
use App\Supplier\Entity\Supplier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\Index(name: 'idx_products_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_products_category', columns: ['category_id'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: Proposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Proposal $originProposal = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $unitPriceCents;

    #[ORM\Column(nullable: true)]
    private ?int $basePriceCents = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $minQuantity = 1;

    #[ORM\Column(length: 20, enumType: ProductStatus::class, options: ['default' => 'attivo'])]
    private ProductStatus $status = ProductStatus::Attivo;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $images;

    public function __construct(Supplier $supplier, string $name, int $unitPriceCents)
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Product name cannot be empty.');
        }

        if ($unitPriceCents < 0) {
            throw new \InvalidArgumentException('Unit price cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->supplier = $supplier;
        $this->name = $name;
        $this->unitPriceCents = $unitPriceCents;
        $this->createdAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getOriginProposal(): ?Proposal
    {
        return $this->originProposal;
    }

    public function setOriginProposal(?Proposal $originProposal): self
    {
        $this->originProposal = $originProposal;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Product name cannot be empty.');
        }

        $this->name = $name;

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

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function setUnitPriceCents(int $unitPriceCents): self
    {
        if ($unitPriceCents < 0) {
            throw new \InvalidArgumentException('Unit price cannot be negative.');
        }

        $this->unitPriceCents = $unitPriceCents;

        return $this;
    }

    public function getBasePriceCents(): ?int
    {
        return $this->basePriceCents;
    }

    public function setBasePriceCents(?int $basePriceCents): self
    {
        if (null !== $basePriceCents && $basePriceCents < 0) {
            throw new \InvalidArgumentException('Base price cannot be negative.');
        }

        $this->basePriceCents = $basePriceCents;

        return $this;
    }

    public function getMinQuantity(): int
    {
        return $this->minQuantity;
    }

    public function setMinQuantity(int $minQuantity): self
    {
        if ($minQuantity <= 0) {
            throw new \InvalidArgumentException('Minimum quantity must be greater than zero.');
        }

        $this->minQuantity = $minQuantity;

        return $this;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function setStatus(ProductStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ProductImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ProductImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }

        return $this;
    }
}
