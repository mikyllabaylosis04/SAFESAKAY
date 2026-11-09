<?php

namespace App\Enums;

enum BoatStatus: string
{
    case Available = 'available';
    case OnDuty = 'on_duty';
    case OnTrip = 'on_trip';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::OnDuty => 'On Duty',
            self::OnTrip => 'On Trip',
            self::Maintenance => 'Maintenance',
            self::Unavailable => 'Unavailable',
        };
    }

    public function isAssignable(): bool
    {
        return $this === self::Available;
    }

    public function isOutOfService(): bool
    {
        return $this === self::Maintenance || $this === self::Unavailable;
    }

    /**
     * Statuses the admin sets. On Duty and On Trip follow today's trips.
     *
     * @return list<self>
     */
    public static function adminManaged(): array
    {
        return [
            self::Available,
            self::Maintenance,
            self::Unavailable,
        ];
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
     * @return list<array{value: string, label: string}>
     */
    public static function adminOptions(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::adminManaged(),
        );
    }
}
