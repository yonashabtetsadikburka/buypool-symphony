<?php

namespace App\Payment\Entity;

use App\Payment\Repository\StripeEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StripeEventRepository::class)]
#[ORM\Table(name: 'stripe_events')]
#[ORM\Index(name: 'idx_stripe_events_type', columns: ['event_type'])]
class StripeEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $eventType;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Payment $payment = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $processed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    public function __construct(string $eventId, string $eventType)
    {
        $eventId = trim($eventId);
        if ('' === $eventId) {
            throw new \InvalidArgumentException('Stripe event id cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function markProcessed(): self
    {
        $this->processed = true;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
