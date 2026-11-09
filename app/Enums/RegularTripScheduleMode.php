<?php

namespace App\Enums;

enum RegularTripScheduleMode: string
{
    case Timed = 'timed';
    case Rush = 'rush';

    public function label(): string
    {
        return match ($this) {
            self::Timed => 'Timed',
            self::Rush => 'Leave When Ready',
        };
    }
}
