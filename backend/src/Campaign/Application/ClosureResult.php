<?php

namespace App\Campaign\Application;

/** Esito della chiusura di un gruppo scaduto (legacy: il riepilogo di chiusura_colletta). */
final class ClosureResult
{
    public function __construct(
        public readonly string $campaignId,
        /** Saldi addebitati (o a zero) con adesione ora "pagata". */
        public readonly int $paid = 0,
        /** Saldi che la banca non ha accettato: il cliente ha un termine per sistemare il pagamento. */
        public readonly int $actionRequired = 0,
        /** Adesioni rimborsate (gruppo sotto il minimo o annullato). */
        public readonly int $refunded = 0,
        public readonly int $refundsFailed = 0,
        /** Adesioni lasciate al prossimo giro (esito non definitivo, errore, gruppo occupato). */
        public readonly int $skipped = 0,
        /** Il gruppo ha ora la data di chiusura. */
        public readonly bool $closed = false,
        /** Il gruppo era già stato chiuso: nulla da fare. */
        public readonly bool $alreadyClosed = false,
    ) {
    }

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->campaignId,
            'pagate' => $this->paid,
            'azione_richiesta' => $this->actionRequired,
            'rimborsate' => $this->refunded,
            'rimborsi_falliti' => $this->refundsFailed,
            'saltate' => $this->skipped,
            'chiusa' => $this->closed,
            'gia_chiusa' => $this->alreadyClosed,
        ];
    }
}
