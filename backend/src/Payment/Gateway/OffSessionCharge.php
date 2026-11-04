<?php

namespace App\Payment\Gateway;

/** Addebito del saldo senza il cliente davanti, con la carta salvata al momento dell'acconto. */
final class OffSessionCharge
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $customerId,
        public readonly string $paymentMethodId,
        public readonly int $amountCents,
        public readonly array $metadata,
        public readonly string $idempotencyKey,
    ) {
    }
}
