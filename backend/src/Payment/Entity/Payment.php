<?php

namespace App\Payment\Entity;

use App\Participation\Entity\Participation;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\Index(name: 'idx_payments_stripe_intent', columns: ['stripe_payment_intent_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Participation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Participation $participation;

    #[ORM\Column(length: 20, enumType: PaymentType::class)]
    private PaymentType $type;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(options: ['default' => 0])]
    private int $agencyCommissionCents = 0;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeChargeId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stripePaymentMethod = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stripeStatus = null;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class, options: ['default' => 'in_attesa'])]
    private PaymentStatus $status = PaymentStatus::InAttesa;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(Participation $participation, PaymentType $type, int $amountCents)
    {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->participation = $participation;
        $this->type = $type;
        $this->amountCents = $amountCents;
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

    public function getType(): PaymentType
    {
        return $this->type;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): self
    {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative.');
        }

        $this->amountCents = $amountCents;

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

    public function getAgencyCommissionCents(): int
    {
        return $this->agencyCommissionCents;
    }

    public function setAgencyCommissionCents(int $agencyCommissionCents): self
    {
        if ($agencyCommissionCents < 0) {
            throw new \InvalidArgumentException('Commission cannot be negative.');
        }

        $this->agencyCommissionCents = $agencyCommissionCents;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): self
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }

    public function getStripeChargeId(): ?string
    {
        return $this->stripeChargeId;
    }

    public function setStripeChargeId(?string $stripeChargeId): self
    {
        $this->stripeChargeId = $stripeChargeId;

        return $this;
    }

    public function getStripePaymentMethod(): ?string
    {
        return $this->stripePaymentMethod;
    }

    public function setStripePaymentMethod(?string $stripePaymentMethod): self
    {
        $this->stripePaymentMethod = $stripePaymentMethod;

        return $this;
    }

    public function getStripeStatus(): ?string
    {
        return $this->stripeStatus;
    }

    public function setStripeStatus(?string $stripeStatus): self
    {
        $this->stripeStatus = $stripeStatus;

        return $this;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
}
