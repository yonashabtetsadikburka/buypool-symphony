<?php

namespace App\Notification\Entity;

use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_user_read', columns: ['user_id', 'is_read'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 30, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 150)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 20, enumType: ReferenceType::class, nullable: true)]
    private ?ReferenceType $referenceType = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $referenceId = null;

    #[ORM\Column(name: 'is_read', options: ['default' => false])]
    private bool $read = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, NotificationType $type, string $title, string $message)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getReferenceType(): ?ReferenceType
    {
        return $this->referenceType;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function setReference(?ReferenceType $referenceType, ?string $referenceId): self
    {
        $this->referenceType = $referenceType;
        $this->referenceId = $referenceId;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->read;
    }

    public function markRead(): self
    {
        $this->read = true;
        $this->readAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
