<?php

namespace App\Payment\Repository;

use App\Participation\Entity\Participation;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Pagamenti confermati di un'adesione dei tipi dati (quelli ancora da rimborsare).
     *
     * @param list<PaymentType> $types
     *
     * @return list<Payment>
     */
    public function findConfirmed(Participation $participation, array $types): array
    {
        /** @var list<Payment> $found */
        $found = $this->createQueryBuilder('pg')
            ->where('pg.participation = :participation')
            ->andWhere('pg.status = :status')
            ->andWhere('pg.type IN (:types)')
            ->setParameter('participation', $participation->getId(), 'uuid')
            ->setParameter('status', PaymentStatus::Confermato)
            ->setParameter('types', $types)
            ->orderBy('pg.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $found;
    }

    /** Metodo di pagamento salvato con l'ultimo acconto: serve ad addebitare il saldo senza il cliente. */
    public function findLatestDepositMethod(Participation $participation): ?string
    {
        $deposits = $this->createQueryBuilder('pg')
            ->where('pg.participation = :participation')
            ->andWhere('pg.type = :type')
            ->andWhere('pg.status = :status')
            ->setParameter('participation', $participation->getId(), 'uuid')
            ->setParameter('type', PaymentType::Acconto)
            ->setParameter('status', PaymentStatus::Confermato)
            ->orderBy('pg.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $method = $deposits[0]?->getStripePaymentMethod();

        return null === $method || '' === $method ? null : $method;
    }

    /** Numero di tentativi di saldo già registrati (riusciti o falliti): il prossimo è questo + 1. */
    public function countBalanceAttempts(Participation $participation): int
    {
        return (int) $this->createQueryBuilder('pg')
            ->select('COUNT(pg.id)')
            ->where('pg.participation = :participation')
            ->andWhere('pg.type = :type')
            ->setParameter('participation', $participation->getId(), 'uuid')
            ->setParameter('type', PaymentType::Saldo)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByStripePaymentIntentId(string $paymentIntentId): ?Payment
    {
        return $this->findOneBy(['stripePaymentIntentId' => trim($paymentIntentId)]);
    }
}
