<?php

namespace App\Payment\Repository;

use App\Payment\Entity\Payment;
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

    public function findOneByStripePaymentIntentId(string $paymentIntentId): ?Payment
    {
        return $this->findOneBy(['stripePaymentIntentId' => trim($paymentIntentId)]);
    }
}
