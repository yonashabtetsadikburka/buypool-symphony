<?php

namespace App\Supplier\Entity;

use App\Campaign\Entity\Campaign;
use App\Identity\Entity\User;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SupplierOrderRepository::class)]
#[ORM\Table(name: 'supplier_orders')]
#[ORM\UniqueConstraint(name: 'uniq_supplier_order_number', columns: ['order_number'])]
#[ORM\Index(name: 'idx_supplier_order_year', columns: ['order_year'])]
class SupplierOrder
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $admin = null;

    #[ORM\Column]
    private int $orderedQuantity;

    #[ORM\Column(nullable: true)]
    private ?int $negotiatedPriceCents = null;

    #[ORM\Column]
    private int $totalCents;

    #[ORM\Column(length: 30, enumType: SupplierOrderStatus::class, options: ['default' => 'inviato'])]
    private SupplierOrderStatus $status = SupplierOrderStatus::Inviato;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(length: 20)]
    private string $orderNumber;

    #[ORM\Column]
    private int $orderYear;

    public function __construct(
        Campaign $campaign,
        Supplier $supplier,
        string $orderNumber,
        int $orderYear,
        int $orderedQuantity,
        int $totalCents,
    ) {
        $orderNumber = trim($orderNumber);
        if ('' === $orderNumber) {
            throw new \InvalidArgumentException('Order number cannot be empty.');
        }

        if ($orderedQuantity <= 0) {
            throw new \InvalidArgumentException('Ordered quantity must be greater than zero.');
        }

        if ($totalCents < 0) {
            throw new \InvalidArgumentException('Total amount cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->campaign = $campaign;
        $this->supplier = $supplier;
        $this->orderNumber = $orderNumber;
        $this->orderYear = $orderYear;
        $this->orderedQuantity = $orderedQuantity;
        $this->totalCents = $totalCents;
        $this->orderedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function setAdmin(?User $admin): self
    {
        $this->admin = $admin;

        return $this;
    }

    public function getOrderedQuantity(): int
    {
        return $this->orderedQuantity;
    }

    public function getNegotiatedPriceCents(): ?int
    {
        return $this->negotiatedPriceCents;
    }

    public function setNegotiatedPriceCents(?int $negotiatedPriceCents): self
    {
        if (null !== $negotiatedPriceCents && $negotiatedPriceCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->negotiatedPriceCents = $negotiatedPriceCents;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getStatus(): SupplierOrderStatus
    {
        return $this->status;
    }

    public function setStatus(SupplierOrderStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getOrderedAt(): ?\DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getOrderYear(): int
    {
        return $this->orderYear;
    }
}
