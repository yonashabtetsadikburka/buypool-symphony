<?php

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixturesTest extends DatabaseTestCase
{
    public function testFixturesLoad(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        (new AppFixtures($hasher))->load($this->em);
        $this->em->flush();

        $connection = $this->em->getConnection();

        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM suppliers'));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM campaigns'));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM participations'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM pickup_tokens'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM notifications'));
    }
}
