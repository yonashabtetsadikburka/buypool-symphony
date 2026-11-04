<?php

namespace App\Participation\Repository;

use App\Campaign\Entity\Campaign;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
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

    /**
     * Adesioni del gruppo in uno degli stati dati, dalla più vecchia.
     *
     * @param list<ParticipationStatus> $statuses
     *
     * @return list<Participation>
     */
    public function findByCampaignAndStatuses(Campaign $campaign, array $statuses): array
    {
        /** @var list<Participation> $found */
        $found = $this->createQueryBuilder('p')
            ->where('p.campaign = :campaign')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('campaign', $campaign->getId(), 'uuid')
            ->setParameter('statuses', $statuses)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $found;
    }

    /**
     * Adesioni con il saldo non riuscito il cui termine per sistemare il pagamento è scaduto.
     *
     * @return list<Participation>
     */
    public function findWithExpiredBalance(\DateTimeImmutable $now): array
    {
        /** @var list<Participation> $found */
        $found = $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.balanceDueAt IS NOT NULL')
            ->andWhere('p.balanceDueAt <= :now')
            ->setParameter('status', ParticipationStatus::AzioneRichiesta)
            ->setParameter('now', $now)
            ->orderBy('p.balanceDueAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function findOneByCampaignAndUser(Campaign $campaign, User $user): ?Participation
    {
        return $this->findOneBy(['campaign' => $campaign, 'user' => $user]);
    }
}
