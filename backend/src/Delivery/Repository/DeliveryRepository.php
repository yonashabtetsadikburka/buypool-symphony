<?php

namespace App\Delivery\Repository;

use App\Delivery\Entity\Delivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 */
class DeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Delivery::class);
    }
}
