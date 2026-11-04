<?php

namespace App\Participation\Service;

/** Esito del giro che annulla i saldi non pagati entro il termine. */
final class ExpiryResult
{
    public function __construct(
        public readonly int $cancelledParticipations,
        public readonly int $cancelledGroups,
        public readonly int $refundsFailed,
    ) {
    }
}
