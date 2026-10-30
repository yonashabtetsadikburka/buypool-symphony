<?php

namespace App\Pricing;

/**
 * Voci del saldo, tutte in centesimi e già arrotondate: la somma delle voci mostrate
 * al cliente coincide con l'importo addebitato. Il saldo può essere <= 0.
 */
final readonly class BalanceBreakdown
{
    public function __construct(
        public int $goodsCents,
        public int $commissionCents,
        public int $shippingCents,
        public int $totalCents,
        public int $depositCents,
        public int $balanceCents,
    ) {
    }
}
