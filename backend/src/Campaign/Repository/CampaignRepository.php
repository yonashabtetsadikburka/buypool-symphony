<?php

namespace App\Campaign\Repository;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Campaign>
 */
class CampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    /**
     * @return Campaign[]
     */
    public function findByStatus(CampaignStatus $status): array
    {
        return $this->findBy(['status' => $status], ['deadline' => 'ASC']);
    }
}
