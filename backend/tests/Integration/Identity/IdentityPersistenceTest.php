<?php

namespace App\Tests\Integration\Identity;

use App\Identity\Entity\ExternalIdentity;
use App\Identity\Entity\User;
use App\Identity\Enum\ExternalProvider;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class IdentityPersistenceTest extends DatabaseTestCase
{
    public function testUserIsPersistedAndRetrievedByEmail(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->em->clear();

        $repository = $this->em->getRepository(User::class);
        $found = $repository->findOneByEmail('mario@example.com');

        self::assertInstanceOf(User::class, $found);
        self::assertSame($user->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    public function testDuplicateEmailFails(): void
    {
        $this->em->persist(new User('Mario', 'dup@example.com'));
        $this->em->flush();

        $this->em->persist(new User('Luigi', 'dup@example.com'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testExternalIdentityIsUniquePerProviderAndSubject(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $this->em->persist(new ExternalIdentity(ExternalProvider::Google, 'sub-123', $user));
        $this->em->flush();

        $other = new User('Luigi', 'luigi@example.com');
        $this->em->persist($other);
        $this->em->persist(new ExternalIdentity(ExternalProvider::Google, 'sub-123', $other));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
