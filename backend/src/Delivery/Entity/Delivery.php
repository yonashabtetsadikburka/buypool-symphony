<?php

namespace App\Delivery\Entity;

use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Repository\DeliveryRepository;
use App\Participation\Entity\Participation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeliveryRepository::class)]
#[ORM\Table(name: 'deliveries')]
class Delivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Participation::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Participation $participation;

    #[ORM\Column(length: 30, enumType: DeliveryMode::class)]
    private DeliveryMode $mode;

    #[ORM\ManyToOne(targetEntity: PickupPoint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PickupPoint $pickupPoint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deliveryAddress = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $deliveryCostCents = 0;

    #[ORM\Column(length: 20, enumType: DeliveryStatus::class, options: ['default' => 'in_attesa'])]
    private DeliveryStatus $status = DeliveryStatus::InAttesa;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    public function __construct(Participation $participation, DeliveryMode $mode)
    {
        $this->id = Uuid::v7();
        $this->participation = $participation;
        $this->mode = $mode;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParticipation(): Participation
    {
        return $this->participation;
    }

    public function getMode(): DeliveryMode
    {
        return $this->mode;
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

    public function getDeliveryAddress(): ?string
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?string $deliveryAddress): self
    {
        $this->deliveryAddress = $deliveryAddress;

        return $this;
    }

    public function getDeliveryCostCents(): int
    {
        return $this->deliveryCostCents;
    }

    public function setDeliveryCostCents(int $deliveryCostCents): self
    {
        if ($deliveryCostCents < 0) {
            throw new \InvalidArgumentException('Delivery cost cannot be negative.');
        }

        $this->deliveryCostCents = $deliveryCostCents;

        return $this;
    }

    public function getStatus(): DeliveryStatus
    {
        return $this->status;
    }

    public function setStatus(DeliveryStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExpectedAt(): ?\DateTimeImmutable
    {
        return $this->expectedAt;
    }

    public function setExpectedAt(?\DateTimeImmutable $expectedAt): self
    {
        $this->expectedAt = $expectedAt;

        return $this;
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
}
