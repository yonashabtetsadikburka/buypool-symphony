<?php

namespace App\Proposal\Repository;

use App\Proposal\Entity\ProposalTier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProposalTier>
 */
class ProposalTierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProposalTier::class);
    }
}
