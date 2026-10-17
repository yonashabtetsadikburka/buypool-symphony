<?php

namespace App\Tests\Unit\Identity;

use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use App\Identity\Enum\CustomerType;
use App\Identity\Enum\UserRole;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('Mario', 'mario@example.com');

        self::assertSame('Mario', $user->getFirstName());
        self::assertSame('mario@example.com', $user->getEmail());
        self::assertNull($user->getLastName());
        self::assertNull($user->getHashedPassword());
        self::assertSame(CustomerType::Privato, $user->getCustomerType());
        self::assertSame(UserRole::Cliente, $user->getRole());
        self::assertSame(AccountStatus::Attivo, $user->getStatus());
        self::assertNull($user->getPrivacyConsentedAt());
        self::assertNull($user->getConsentVersion());
    }

    public function testEmptyFirstNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('   ', 'mario@example.com');
    }

    public function testEmptyEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('Mario', '');
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('Mario', 'not-an-email');
    }

    public function testRolesAreDerivedFromRole(): void
    {
        $user = new User('Mario', 'mario@example.com');
        self::assertSame(['ROLE_CLIENTE', 'ROLE_USER'], $user->getRoles());

        $user->setRole(UserRole::Admin);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());

        $user->setRole(UserRole::Fornitore);
        self::assertSame(['ROLE_FORNITORE', 'ROLE_USER'], $user->getRoles());
    }

    public function testIdentifierIsEmail(): void
    {
        $user = new User('Mario', 'mario@example.com');

        self::assertSame('mario@example.com', $user->getUserIdentifier());
    }

    public function testBlankLastNameIsNormalizedToNull(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $user->setLastName('   ');

        self::assertNull($user->getLastName());
    }
}
