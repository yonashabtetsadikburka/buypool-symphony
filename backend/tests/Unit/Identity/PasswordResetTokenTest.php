<?php

namespace App\Tests\Unit\Identity;

use App\Identity\Entity\PasswordResetToken;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    public function testEmptyHashIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordResetToken(new User('Mario', 'mario@example.com'), '', new \DateTimeImmutable('+1 day'));
    }

    public function testStartsUnusedAndNotExpired(): void
    {
        $token = new PasswordResetToken(
            new User('Mario', 'mario@example.com'),
            hash('sha256', 'plain-token'),
            new \DateTimeImmutable('+1 day'),
        );

        self::assertFalse($token->isUsed());
        self::assertFalse($token->isExpired(new \DateTimeImmutable()));
    }

    public function testMarkUsed(): void
    {
        $token = new PasswordResetToken(
            new User('Mario', 'mario@example.com'),
            hash('sha256', 'plain-token'),
            new \DateTimeImmutable('+1 day'),
        );

        $token->markUsed();

        self::assertTrue($token->isUsed());
        self::assertNotNull($token->getUsedAt());
    }

    public function testExpiredTokenIsDetected(): void
    {
        $token = new PasswordResetToken(
            new User('Mario', 'mario@example.com'),
            hash('sha256', 'plain-token'),
            new \DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($token->isExpired(new \DateTimeImmutable()));
    }
}
