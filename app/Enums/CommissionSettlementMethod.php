<?php

namespace App\Enums;

enum CommissionSettlementMethod: string
{
    case Gcash = 'gcash';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Gcash => 'GCash',
            self::Cash => 'Cash',
        };
    }
}
