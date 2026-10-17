<?php

namespace App\Delivery\Enum;

enum PickupTokenStatus: string
{
    case Generato = 'generato';
    case Scansionato = 'scansionato';

    /**
     * @deprecated Never written by the legacy code. Kept for parity only.
     */
    case Annullato = 'annullato';
}
