<?php

namespace App\Supplier\Repository;

use App\Supplier\Entity\SupplierOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierOrder>
 */
class SupplierOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierOrder::class);
    }

    public function findOneByOrderNumber(string $orderNumber): ?SupplierOrder
    {
        return $this->findOneBy(['orderNumber' => trim($orderNumber)]);
    }
}
