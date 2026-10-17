<?php

namespace App\Supplier\Repository;

use App\Supplier\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /**
     * @return Supplier[]
     */
    public function findPublic(): array
    {
        return $this->findBy(['isPublic' => true], ['name' => 'ASC']);
    }
}
