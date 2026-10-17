<?php

namespace App\Campaign\Enum;

enum CampaignStatus: string
{
    case InCorso = 'in_corso';
    case Riuscita = 'riuscita';
    case Fallita = 'fallita';
    case OrdinePronto = 'ordine_pronto';
    case OrdineFornitore = 'ordine_fornitore';
    case Consegnata = 'consegnata';
    case Annullata = 'annullata';
}
