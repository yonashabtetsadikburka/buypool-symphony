<?php

namespace App\Tests\Integration\Supplier;

use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierInvite;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SupplierPersistenceTest extends DatabaseTestCase
{
    public function testPublicSupplierQuery(): void
    {
        $this->em->persist(new Supplier('Pubblico Srl'));
        $hidden = new Supplier('Nascosto Srl');
        $hidden->setIsPublic(false);
        $this->em->persist($hidden);
        $this->em->flush();

        $this->em->clear();

        $repository = $this->em->getRepository(Supplier::class);
        $public = $repository->findPublic();

        self::assertCount(1, $public);
        self::assertSame('Pubblico Srl', $public[0]->getName());
    }

    public function testInviteTokenHashIsUnique(): void
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);

        $hash = hash('sha256', 'plain-token');
        $this->em->persist(new SupplierInvite($supplier, 'a@example.com', $hash, new \DateTimeImmutable('+7 days')));
        $this->em->flush();

        $this->em->persist(new SupplierInvite($supplier, 'b@example.com', $hash, new \DateTimeImmutable('+7 days')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
