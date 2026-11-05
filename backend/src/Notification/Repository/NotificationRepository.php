<?php

namespace App\Notification\Repository;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Le notifiche dell'utente, le più recenti per prime.
     *
     * @return list<Notification>
     */
    public function findLatestForUser(User $user, int $limit): array
    {
        /** @var list<Notification> $found */
        $found = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.read = false')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Segna come lette tutte le non lette dell'utente con una sola istruzione; restituisce quante erano. */
    public function markAllReadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.read', 'true')
            ->set('n.readAt', ':now')
            ->where('n.user = :user')
            ->andWhere('n.read = false')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }

    /**
     * @return Notification[]
     */
    public function findUnreadForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'read' => false], ['createdAt' => 'DESC']);
    }
}
