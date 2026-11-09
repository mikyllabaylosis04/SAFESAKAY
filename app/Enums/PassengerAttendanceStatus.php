<?php

namespace App\Enums;

enum PassengerAttendanceStatus: string
{
    case NotYetArrived = 'not_yet_arrived';
    case Arrived = 'arrived';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::NotYetArrived => 'Not Yet Arrived',
            self::Arrived => 'Arrived',
            self::NoShow => 'No-Show',
        };
    }
}
