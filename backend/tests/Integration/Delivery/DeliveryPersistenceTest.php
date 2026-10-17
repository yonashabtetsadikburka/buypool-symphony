<?php

namespace App\Tests\Integration\Delivery;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Supplier\Entity\Supplier;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DeliveryPersistenceTest extends DatabaseTestCase
{
    private function makeParticipation(): Participation
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);
        $product = new Product($supplier, 'Zaino 20L', 1990);
        $this->em->persist($product);
        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($campaign);
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $participation = new Participation($campaign, $user, 3);
        $this->em->persist($participation);

        return $participation;
    }

    public function testParticipationCanHaveMultiplePickupTokens(): void
    {
        $participation = $this->makeParticipation();
        $this->em->persist(new PickupToken($participation, hash('sha256', 'a'), 1));
        $this->em->persist(new PickupToken($participation, hash('sha256', 'b'), 2));
        $this->em->flush();

        $repository = $this->em->getRepository(PickupToken::class);
        self::assertCount(2, $repository->findBy(['participation' => $participation]));
    }

    public function testPickupTokenHashIsUnique(): void
    {
        $participation = $this->makeParticipation();
        $hash = hash('sha256', 'a');
        $this->em->persist(new PickupToken($participation, $hash, 1));
        $this->em->flush();

        $this->em->persist(new PickupToken($participation, $hash, 2));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testFindPickupTokenByHash(): void
    {
        $participation = $this->makeParticipation();
        $this->em->persist(new PickupToken($participation, hash('sha256', 'lookup'), 1));
        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(PickupToken::class);
        $found = $repository->findOneByTokenHash(hash('sha256', 'lookup'));

        self::assertNotNull($found);
        self::assertSame(1, $found->getAssignedQuantity());
    }

    public function testOneDeliveryPerParticipation(): void
    {
        $participation = $this->makeParticipation();
        $point = new PickupPoint('Sede Centrale', 'Via Roma 1', 'Milano');
        $this->em->persist($point);

        $this->em->persist(new Delivery($participation, DeliveryMode::RitiroSede));
        $this->em->flush();

        $this->em->persist(new Delivery($participation, DeliveryMode::ConsegnaDomicilio));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
