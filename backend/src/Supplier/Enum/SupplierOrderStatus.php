<?php

namespace App\Supplier\Enum;

enum SupplierOrderStatus: string
{
    /**
     * @deprecated Never written by the legacy code (order is always created as Inviato).
     *             Kept for parity and read guards only, never used in transitions.
     */
    case DaNegoziare = 'da_negoziare';

    case Inviato = 'inviato';
    case Ricevuto = 'ricevuto';
    case InPreparazione = 'in_preparazione';

    /** Spedito dal fornitore verso la Sede Centrale (legacy: fornitore_ordine_spedisci). Prima di "merce arrivata". */
    case Spedito = 'spedito';
    case Evaso = 'evaso';

    /**
     * @deprecated Never written by the legacy code (unreachable). Kept for parity/read guards only.
     */
    case Consegnato = 'consegnato';

    /**
     * @deprecated Never written by the legacy code. Kept for parity only.
     */
    case Annullato = 'annullato';
}
