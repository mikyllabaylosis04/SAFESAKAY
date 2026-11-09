<?php

namespace App\Enums;

enum TripConfirmedBy: string
{
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Operator => 'Operator',
        };
    }
}
