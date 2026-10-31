<?php

namespace App\Enums;

enum TenantType: string
{
    case Personal = 'personal';
    case Company = 'company';
    case Partner = 'partner';
}
