<?php

namespace App\Delivery\Repository;

use App\Delivery\Entity\PickupToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PickupToken>
 */
class PickupTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PickupToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?PickupToken
    {
        return $this->findOneBy(['tokenHash' => trim($tokenHash)]);
    }
}
