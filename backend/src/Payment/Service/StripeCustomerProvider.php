<?php

namespace App\Payment\Service;

use App\Identity\Entity\User;
use App\Payment\Gateway\StripeGateway;
use Doctrine\ORM\EntityManagerInterface;

/** Il Customer Stripe dell'utente: creato la prima volta, poi riusato (serve per gli addebiti futuri del saldo). */
final class StripeCustomerProvider
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function customerIdFor(User $user): string
    {
        $customerId = $user->getStripeCustomerId();
        if (null === $customerId || '' === $customerId) {
            $customerId = $this->stripe->createCustomer($user->getEmail(), $user->getId()->toRfc4122());
            $user->setStripeCustomerId($customerId);
            $this->em->flush();
        }

        return $customerId;
    }
}
