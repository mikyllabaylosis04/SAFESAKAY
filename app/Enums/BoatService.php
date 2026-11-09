<?php

namespace App\Enums;

enum BoatService: string
{
    case RegularTrip = 'regular_trip';
    case PrivateBooking = 'private_booking';

    public function label(): string
    {
        return match ($this) {
            self::RegularTrip => 'Regular Trip',
            self::PrivateBooking => 'Private Booking',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases(),
        );
    }

    /**
     * @param  list<string>|null  $selected
     */
    public static function legacyType(?array $selected): string
    {
        $selected = array_values(array_intersect(self::values(), $selected ?? []));

        if ($selected === []) {
            return 'unassigned';
        }

        if (count($selected) === count(self::values())) {
            return 'both';
        }

        return $selected[0];
    }

    /**
     * @param  list<string>|null  $selected
     */
    public static function summarize(?array $selected): string
    {
        $selected = array_values(array_intersect(self::values(), $selected ?? []));

        if ($selected === []) {
            return 'Not Assigned Yet';
        }

        $labels = array_map(
            fn (string $value) => self::from($value)->label(),
            $selected,
        );

        return implode(' and ', $labels);
    }
}
