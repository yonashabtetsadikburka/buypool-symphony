<?php

namespace App\Payment\Gateway;

/** Stato di una sessione di pagamento Stripe Checkout, riletto da Stripe dopo il ritorno del cliente. */
final class CheckoutSessionStatus
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $paymentStatus,
        public readonly string $paymentIntentId,
        public readonly int $amountTotalCents,
        public readonly array $metadata,
    ) {
    }

    public function isPaid(): bool
    {
        return 'paid' === $this->paymentStatus;
    }
}
