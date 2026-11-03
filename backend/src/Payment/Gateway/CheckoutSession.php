<?php

namespace App\Payment\Gateway;

/** Sessione di pagamento Stripe Checkout (solo i dati che servono a BuyPool). */
final class CheckoutSession
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly int $amountCents,
        public readonly array $metadata = [],
    ) {
    }
}
