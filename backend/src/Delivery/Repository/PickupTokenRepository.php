<?php

namespace App\Delivery\Repository;

use App\Delivery\Entity\PickupToken;
use App\Participation\Entity\Participation;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * I QR di ritiro delle adesioni date, indicizzati per id adesione (RFC 4122).
     *
     * @param list<Participation> $participations
     *
     * @return array<string, list<PickupToken>>
     */
    public function findByParticipations(array $participations): array
    {
        if ([] === $participations) {
            return [];
        }

        /** @var list<PickupToken> $found */
        $found = $this->createQueryBuilder('t')
            ->where('IDENTITY(t.participation) IN (:participations)')
            ->setParameter('participations', array_map(static fn (Participation $p): string => $p->getId()->toBinary(), $participations), ArrayParameterType::BINARY)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byParticipation = [];
        foreach ($found as $token) {
            $byParticipation[$token->getParticipation()->getId()->toRfc4122()][] = $token;
        }

        return $byParticipation;
    }

    public function findOneByTokenHash(string $tokenHash): ?PickupToken
    {
        return $this->findOneBy(['tokenHash' => trim($tokenHash)]);
    }
}
