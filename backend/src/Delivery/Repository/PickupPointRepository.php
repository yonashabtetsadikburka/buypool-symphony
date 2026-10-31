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
     * Sede predefinita: la prima creata. Vale per i gruppi senza punto di ritiro proprio
     * (nel legacy, la sede con l'id più basso). Gli id sono UUID v7, quindi ordinati per creazione.
     */
    public function findDefault(): ?PickupPoint
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * @return PickupPoint[]
     */
    public function findAllOrderedByCity(): array
    {
        return $this->findBy([], ['city' => 'ASC', 'name' => 'ASC']);
    }
}
