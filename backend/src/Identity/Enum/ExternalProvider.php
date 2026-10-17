<?php

namespace App\Identity\Enum;

enum ExternalProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
}
