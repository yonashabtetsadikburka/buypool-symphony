<?php

namespace App\Campaign\Application;

/** Esito dell'annullamento di un gruppo. */
final class CancellationResult
{
    public function __construct(
        public readonly int $refunded,
        public readonly int $failed,
        /** Tutti i rimborsi riusciti: il gruppo è chiuso. Altrimenti si riprende al prossimo giro. */
        public readonly bool $complete,
    ) {
    }
}
