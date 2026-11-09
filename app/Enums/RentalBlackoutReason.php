<?php

namespace App\Enums;

enum RentalBlackoutReason: string
{
    case Fiesta = 'fiesta';
    case Holiday = 'holiday';
    case SpecialEvent = 'special_event';
    case BarangayActivity = 'barangay_activity';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fiesta => 'Fiesta',
            self::Holiday => 'Holiday',
            self::SpecialEvent => 'Special Event',
            self::BarangayActivity => 'Barangay Activity',
            self::Other => 'Other',
        };
    }

    public function phrase(): string
    {
        return match ($this) {
            self::Fiesta => 'a scheduled fiesta',
            self::Holiday => 'a scheduled holiday',
            self::SpecialEvent => 'a scheduled special event',
            self::BarangayActivity => 'a scheduled barangay activity',
            self::Other => 'a scheduled barangay event',
        };
    }
}
