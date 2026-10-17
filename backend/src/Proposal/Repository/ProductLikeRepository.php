<?php

namespace App\Proposal\Repository;

use App\Campaign\Entity\Product;
use App\Proposal\Entity\ProductLike;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductLike>
 */
class ProductLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductLike::class);
    }

    public function countForProduct(Product $product): int
    {
        return $this->count(['product' => $product]);
    }
}
