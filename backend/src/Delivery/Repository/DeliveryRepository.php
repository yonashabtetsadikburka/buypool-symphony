<?php

namespace App\Delivery\Repository;

use App\Delivery\Entity\Delivery;
use App\Participation\Entity\Participation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 */
class DeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Delivery::class);
    }

    /**
     * Le consegne delle adesioni date, con la sede di ritiro, indicizzate per id adesione (RFC 4122).
     *
     * @param list<Participation> $participations
     *
     * @return array<string, Delivery>
     */
    public function findByParticipations(array $participations): array
    {
        if ([] === $participations) {
            return [];
        }

        /** @var list<Delivery> $found */
        $found = $this->createQueryBuilder('d')
            ->addSelect('pp')
            ->leftJoin('d.pickupPoint', 'pp')
            ->where('IDENTITY(d.participation) IN (:participations)')
            ->setParameter('participations', array_map(static fn (Participation $p): string => $p->getId()->toBinary(), $participations), ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();

        $byParticipation = [];
        foreach ($found as $delivery) {
            $byParticipation[$delivery->getParticipation()->getId()->toRfc4122()] = $delivery;
        }

        return $byParticipation;
    }
}
