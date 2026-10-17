<?php

namespace App\Delivery\Enum;

enum DeliveryStatus: string
{
    case InAttesa = 'in_attesa';

    /**
     * @deprecated Never written by the legacy backend. Kept for parity only.
     */
    case Pronta = 'pronta';

    case Spedita = 'spedita';
    case Consegnata = 'consegnata';

    /** Reachable only through the QR pickup path, never through the delivery endpoints. */
    case Ritirata = 'ritirata';
}
