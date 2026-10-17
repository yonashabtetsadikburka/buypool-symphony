<?php

namespace App\Delivery\Enum;

enum DeliveryMode: string
{
    case RitiroSede = 'ritiro_sede';
    case ConsegnaDomicilio = 'consegna_domicilio';
}
