<?php

namespace App\Enums;

enum OrganizationRouteType: string
{
    case RegularTrip = 'regular_public';
    case ExclusiveBoatRental = 'private_booking';

    public function label(): string
    {
        return match ($this) {
            self::RegularTrip => 'Regular Trip',
            self::ExclusiveBoatRental => 'Exclusive Boat Rental',
        };
    }

    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::RegularTrip;
    }
}
