<?php

namespace App\Pricing;

final readonly class PricingSettings
{
    public function __construct(
        /** Percentuale dell'acconto sul prezzo del primo scaglione (legacy: acconto_percent = 20). */
        public int|float|string $depositPercent,
        /** Costo della consegna a domicilio in centesimi, addebitato nel saldo. */
        public int $homeDeliveryCents,
    ) {
    }
}
