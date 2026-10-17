<?php

namespace App\Participation\Enum;

enum ParticipationStatus: string
{
    case Prenotata = 'prenotata';
    case Confermata = 'confermata';
    case Pagata = 'pagata';

    /**
     * @deprecated Never written by the legacy code (withdrawal performs a physical DELETE).
     *             Kept for parity only, never used in transitions.
     */
    case Annullata = 'annullata';

    case Rimborsata = 'rimborsata';
    case AzioneRichiesta = 'azione_richiesta';
}
