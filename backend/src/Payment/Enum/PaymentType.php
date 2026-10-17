<?php

namespace App\Payment\Enum;

enum PaymentType: string
{
    case Acconto = 'acconto';
    case Saldo = 'saldo';
    case Consegna = 'consegna';
}
