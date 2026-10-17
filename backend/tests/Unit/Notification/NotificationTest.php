<?php

namespace App\Tests\Unit\Notification;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testSogliaPersaTypeExistsForParity(): void
    {
        $values = array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases());

        self::assertContains('SOGLIA_PERSA', $values);
    }

    public function testDefaultsAndReference(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $notification = new Notification($user, NotificationType::Sistema, 'Titolo', 'Messaggio');

        self::assertFalse($notification->isRead());
        self::assertNull($notification->getReadAt());
        self::assertNull($notification->getReferenceType());

        $notification->setReference(ReferenceType::Colletta, 'rfc-uuid');
        self::assertSame(ReferenceType::Colletta, $notification->getReferenceType());
        self::assertSame('rfc-uuid', $notification->getReferenceId());
    }

    public function testMarkRead(): void
    {
        $notification = new Notification(
            new User('Mario', 'mario@example.com'),
            NotificationType::Sistema,
            'Titolo',
            'Messaggio',
        );

        $notification->markRead();

        self::assertTrue($notification->isRead());
        self::assertNotNull($notification->getReadAt());
    }
}
