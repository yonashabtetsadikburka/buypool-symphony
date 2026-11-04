<?php

namespace App\Payment\Service;

/** Esito del rimborso dei pagamenti di un'adesione. */
final class RefundResult
{
    public function __construct(
        /** Totale rimborsato in questa chiamata, in centesimi. */
        public readonly int $refundedCents,
        /** Pagamenti il cui rimborso non è riuscito (si riprova al giro dopo). */
        public readonly int $failed,
    ) {
    }

    public function isComplete(): bool
    {
        return 0 === $this->failed;
    }
}
