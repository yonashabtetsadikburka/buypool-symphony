<?php

namespace App\Payment\Enum;

enum PaymentStatus: string
{
    case InAttesa = 'in_attesa';
    case Confermato = 'confermato';
    case Fallito = 'fallito';
    case Rimborsato = 'rimborsato';
}
