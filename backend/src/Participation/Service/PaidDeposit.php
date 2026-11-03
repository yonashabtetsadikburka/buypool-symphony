<?php

namespace App\Participation\Service;

/** Un acconto pagato su Stripe (PaymentIntent riuscito), come lo vede BuyPool. */
final class PaidDeposit
{
    /**
     * @param array<string, string> $metadata quelli messi da {@see DepositCheckoutService}
     */
    public function __construct(
        public readonly string $paymentIntentId,
        public readonly int $amountCents,
        public readonly string $paymentMethod,
        public readonly string $stripeStatus,
        public readonly array $metadata,
    ) {
    }
}
