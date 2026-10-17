<?php

namespace App\Identity\Entity;

use App\Identity\Enum\ExternalProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'external_identities')]
#[ORM\UniqueConstraint(name: 'uniq_external_identity_provider_subject', columns: ['provider', 'subject'])]
class ExternalIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ExternalProvider::class)]
    private ExternalProvider $provider;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ExternalProvider $provider, string $subject, User $user)
    {
        $subject = trim($subject);
        if ('' === $subject) {
            throw new \InvalidArgumentException('Provider subject cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->subject = $subject;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): ExternalProvider
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
