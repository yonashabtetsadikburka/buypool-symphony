<?php

namespace App\Participation\Repository;

use App\Campaign\Entity\Campaign;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Participation>
 */
class ParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participation::class);
    }

    /**
     * @return Participation[]
     */
    public function findByCampaign(Campaign $campaign): array
    {
        return $this->findBy(['campaign' => $campaign], ['createdAt' => 'ASC']);
    }

    public function findOneByCampaignAndUser(Campaign $campaign, User $user): ?Participation
    {
        return $this->findOneBy(['campaign' => $campaign, 'user' => $user]);
    }
}
