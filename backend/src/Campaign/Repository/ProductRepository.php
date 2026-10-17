<?php

namespace App\Campaign\Repository;

use App\Campaign\Entity\Product;
use App\Campaign\Enum\ProductStatus;
use App\Supplier\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return Product[]
     */
    public function findActiveBySupplier(Supplier $supplier): array
    {
        return $this->findBy(['supplier' => $supplier, 'status' => ProductStatus::Attivo], ['name' => 'ASC']);
    }
}
