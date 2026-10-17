<?php

namespace App\Identity\Enum;

enum AccountStatus: string
{
    case Attivo = 'attivo';
    case Sospeso = 'sospeso';
}
