<?php

namespace App\Enums;

enum BoatBookingStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Ongoing = 'ongoing';
    case WeatherHold = 'weather_hold';
    case ReschedulePending = 'reschedule_pending';
    case Completed = 'completed';
    case PassengerNoShow = 'passenger_no_show';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::AwaitingPayment => 'Awaiting Payment',
            self::Confirmed => 'Confirmed',
            self::Ongoing => 'Ongoing',
            self::WeatherHold => 'Weather Hold',
            self::ReschedulePending => 'Reschedule Pending',
            self::Completed => 'Completed',
            self::PassengerNoShow => 'Passenger No-Show',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function isUpcoming(): bool
    {
        return in_array($this, [
            self::Pending,
            self::AwaitingPayment,
            self::Confirmed,
            self::Ongoing,
            self::WeatherHold,
            self::ReschedulePending,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function rentalBoard(): array
    {
        return [
            self::Pending,
            self::AwaitingPayment,
            self::Confirmed,
            self::Ongoing,
            self::WeatherHold,
            self::ReschedulePending,
            self::Completed,
            self::PassengerNoShow,
        ];
    }

    public function isWeatherPaused(): bool
    {
        return in_array($this, [self::WeatherHold, self::ReschedulePending], true);
    }
}
