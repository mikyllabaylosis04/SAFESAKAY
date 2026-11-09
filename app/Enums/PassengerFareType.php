<?php

namespace App\Enums;

enum PassengerFareType: string
{
    case Regular = 'regular';
    case Student = 'student';
    case Senior = 'senior';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Student => 'Student',
            self::Senior => 'Senior',
        };
    }

    public static function fromAge(int $age): self
    {
        return match (true) {
            $age >= 60 => self::Senior,
            $age <= 21 => self::Student,
            default => self::Regular,
        };
    }

    public static function tryFromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::Regular;
    }
}
