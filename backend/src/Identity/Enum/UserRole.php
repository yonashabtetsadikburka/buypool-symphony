<?php

namespace App\Identity\Enum;

enum UserRole: string
{
    case Cliente = 'cliente';
    case Fornitore = 'fornitore';
    case Admin = 'admin';
}
