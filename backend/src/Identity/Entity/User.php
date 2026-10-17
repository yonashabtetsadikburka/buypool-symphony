<?php

namespace App\Identity\Entity;

use App\Identity\Enum\AccountStatus;
use App\Identity\Enum\CustomerType;
use App\Identity\Enum\UserRole;
use App\Identity\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'idx_users_role', columns: ['role'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hashedPassword = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $zipCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(length: 20, enumType: CustomerType::class, options: ['default' => 'privato'])]
    private CustomerType $customerType = CustomerType::Privato;

    #[ORM\Column(length: 20, enumType: UserRole::class, options: ['default' => 'cliente'])]
    private UserRole $role = UserRole::Cliente;

    #[ORM\Column(length: 20, enumType: AccountStatus::class, options: ['default' => 'attivo'])]
    private AccountStatus $status = AccountStatus::Attivo;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $fiscalCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $privacyConsentedAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $consentVersion = null;

    public function __construct(string $firstName, string $email)
    {
        if ('' === trim($firstName)) {
            throw new \InvalidArgumentException('First name cannot be empty.');
        }

        $this->id = Uuid::v7();
        $this->firstName = trim($firstName);
        $this->setEmail($email);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        if ('' === trim($firstName)) {
            throw new \InvalidArgumentException('First name cannot be empty.');
        }

        $this->firstName = trim($firstName);

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = (null === $lastName || '' === trim($lastName)) ? null : trim($lastName);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $email = trim($email);
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $this->email = $email;

        return $this;
    }

    public function getHashedPassword(): ?string
    {
        return $this->hashedPassword;
    }

    public function setHashedPassword(?string $hashedPassword): self
    {
        $this->hashedPassword = $hashedPassword;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->hashedPassword;
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

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function setZipCode(?string $zipCode): self
    {
        $this->zipCode = $zipCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(?string $province): self
    {
        $this->province = $province;

        return $this;
    }

    public function getCustomerType(): CustomerType
    {
        return $this->customerType;
    }

    public function setCustomerType(CustomerType $customerType): self
    {
        $this->customerType = $customerType;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function setStatus(AccountStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->stripeCustomerId = $stripeCustomerId;

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

    public function getFiscalCode(): ?string
    {
        return $this->fiscalCode;
    }

    public function setFiscalCode(?string $fiscalCode): self
    {
        $this->fiscalCode = $fiscalCode;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPrivacyConsentedAt(): ?\DateTimeImmutable
    {
        return $this->privacyConsentedAt;
    }

    public function setPrivacyConsentedAt(?\DateTimeImmutable $privacyConsentedAt): self
    {
        $this->privacyConsentedAt = $privacyConsentedAt;

        return $this;
    }

    public function getConsentVersion(): ?string
    {
        return $this->consentVersion;
    }

    public function setConsentVersion(?string $consentVersion): self
    {
        $this->consentVersion = $consentVersion;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_'.strtoupper($this->role->value), 'ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
