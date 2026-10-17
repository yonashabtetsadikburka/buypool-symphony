<?php

namespace App\Tests\Integration\Supplier;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierOrder;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SupplierOrderPersistenceTest extends DatabaseTestCase
{
    public function testOrderNumberLookupAndUniqueness(): void
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);
        $product = new Product($supplier, 'Zaino 20L', 1990);
        $this->em->persist($product);

        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($campaign);

        $order = new SupplierOrder($campaign, $supplier, '2026-000123', 2026, 50, 99500);
        $this->em->persist($order);
        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(SupplierOrder::class);
        $found = $repository->findOneByOrderNumber('2026-000123');

        self::assertInstanceOf(SupplierOrder::class, $found);
        self::assertSame(50, $found->getOrderedQuantity());
    }

    public function testOrderNumberIsUnique(): void
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);
        $product = new Product($supplier, 'Zaino 20L', 1990);
        $this->em->persist($product);

        $first = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($first);
        $second = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($second);

        $this->em->persist(new SupplierOrder($first, $supplier, '2026-000123', 2026, 5, 9950));
        $this->em->flush();

        $this->em->persist(new SupplierOrder($second, $supplier, '2026-000123', 2026, 5, 9950));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
