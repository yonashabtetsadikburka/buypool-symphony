<?php

namespace App\Payment\Gateway;

/** Richiesta di una sessione di pagamento per l'acconto di un'adesione. */
final class DepositCheckoutRequest
{
    /**
     * @param array<string, string> $metadata copiati sulla sessione e sul PaymentIntent
     */
    public function __construct(
        public readonly string $customerId,
        public readonly string $productName,
        public readonly string $description,
        public readonly int $amountCents,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly array $metadata,
        public readonly string $idempotencyKey,
    ) {
    }
}
