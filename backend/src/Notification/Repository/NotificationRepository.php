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
     * @return Notification[]
     */
    public function findUnreadForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'read' => false], ['createdAt' => 'DESC']);
    }
}
