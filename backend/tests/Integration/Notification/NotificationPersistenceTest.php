<?php

namespace App\Tests\Integration\Notification;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Tests\Integration\DatabaseTestCase;

final class NotificationPersistenceTest extends DatabaseTestCase
{
    public function testFindUnreadForUser(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);

        $unread = new Notification($user, NotificationType::Sistema, 'Nuovo', 'Corpo');
        $read = new Notification($user, NotificationType::Sistema, 'Vecchio', 'Corpo');
        $read->markRead();

        $this->em->persist($unread);
        $this->em->persist($read);
        $this->em->flush();
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneByEmail('mario@example.com');
        $repository = $this->em->getRepository(Notification::class);
        $result = $repository->findUnreadForUser($user);

        self::assertCount(1, $result);
        self::assertSame('Nuovo', $result[0]->getTitle());
    }

    public function testPolymorphicReferenceRoundTrip(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $notification = new Notification($user, NotificationType::NuovoScaglione, 'Scaglione', 'Corpo');
        $notification->setReference(ReferenceType::Prenotazione, '01928b1e-0000-7000-8000-000000000000');
        $this->em->persist($notification);
        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(Notification::class);
        $found = $repository->findAll()[0];

        self::assertSame(ReferenceType::Prenotazione, $found->getReferenceType());
        self::assertSame('01928b1e-0000-7000-8000-000000000000', $found->getReferenceId());
    }
}
