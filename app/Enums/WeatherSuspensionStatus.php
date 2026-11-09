<?php

namespace App\Enums;

enum WeatherSuspensionStatus: string
{
    case Active = 'active';
    case Lifted = 'lifted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Lifted => 'Lifted',
        };
    }
}
