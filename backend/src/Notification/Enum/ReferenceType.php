<?php

namespace App\Notification\Enum;

enum ReferenceType: string
{
    case Colletta = 'colletta';
    case Prenotazione = 'prenotazione';
    case Proposta = 'proposta';
}
