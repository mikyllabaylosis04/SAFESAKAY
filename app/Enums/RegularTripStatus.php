<?php

namespace App\Enums;

enum RegularTripStatus: string
{
    case Scheduled = 'scheduled';
    case OnTrip = 'on_trip';
    case Delayed = 'delayed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case OffDuty = 'off_duty';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled, self::Delayed => 'On Duty',
            self::OnTrip => 'On Trip',
            self::OffDuty => 'Off Duty',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isWaiting(): bool
    {
        return in_array($this, [self::Scheduled, self::Delayed], true);
    }

    public function isLineup(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Delayed,
            self::OnTrip,
            self::OffDuty,
        ], true);
    }

    /**
     * Active boat statuses shown in Regular Trip Rotation.
     *
     * @return list<self>
     */
    public static function monitorable(): array
    {
        return [
            self::Scheduled,
            self::OnTrip,
            self::OffDuty,
        ];
    }
}
