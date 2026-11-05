<?php

namespace App\Participation\Service;

/** Esito della conferma di un acconto pagato. */
enum DepositOutcome: string
{
    /** Adesione creata e quantità del gruppo aumentata. */
    case Created = 'creata';
    /** Pagamento già registrato (evento ripetuto): non si fa nulla. */
    case AlreadyHandled = 'gia_gestito';
    /** Pagamento in più o in ritardo: rimborsato per intero. */
    case Refunded = 'rimborsato';
    /** Dati mancanti o incoerenti: nessuna azione (il legacy chiudeva l'evento e scriveva nel log). */
    case Ignored = 'ignorato';
}
