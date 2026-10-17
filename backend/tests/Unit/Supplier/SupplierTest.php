<?php

namespace App\Tests\Unit\Supplier;

use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierInvite;
use PHPUnit\Framework\TestCase;

final class SupplierTest extends TestCase
{
    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Supplier('  ');
    }

    public function testDefaults(): void
    {
        $supplier = new Supplier('Acme Srl');

        self::assertSame('Acme Srl', $supplier->getName());
        self::assertTrue($supplier->isPublic());
        self::assertNull($supplier->getUser());
    }

    public function testInviteRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SupplierInvite(new Supplier('Acme Srl'), 'bad', hash('sha256', 't'), new \DateTimeImmutable('+7 days'));
    }

    public function testInviteRejectsEmptyHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SupplierInvite(new Supplier('Acme Srl'), 'a@example.com', '', new \DateTimeImmutable('+7 days'));
    }

    public function testInviteLifecycle(): void
    {
        $invite = new SupplierInvite(
            new Supplier('Acme Srl'),
            'a@example.com',
            hash('sha256', 'plain'),
            new \DateTimeImmutable('+7 days'),
        );

        self::assertFalse($invite->isUsed());
        self::assertFalse($invite->isExpired(new \DateTimeImmutable()));

        $invite->markUsed();

        self::assertTrue($invite->isUsed());
    }
}
