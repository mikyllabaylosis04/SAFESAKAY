<?php

namespace App\Enums;

enum GcashPayee: string
{
    case Operator = 'operator';
    case Barangay = 'barangay';

    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Boat operator GCash',
            self::Barangay => 'SafeSakay',
        };
    }
}
