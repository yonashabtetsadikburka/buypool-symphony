<?php

namespace App\Delivery\Repository;

use App\Delivery\Entity\PickupPoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PickupPoint>
 */
class PickupPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PickupPoint::class);
    }

    /**
     * @return PickupPoint[]
     */
    public function findAllOrderedByCity(): array
    {
        return $this->findBy([], ['city' => 'ASC', 'name' => 'ASC']);
    }
}
