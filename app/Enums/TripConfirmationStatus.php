<?php

namespace App\Enums;

enum TripConfirmationStatus: string
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed = 'confirmed';
    case CannotOperate = 'cannot_operate';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Awaiting Confirmation',
            self::Confirmed => 'Confirmed',
            self::CannotOperate => 'Cannot Operate',
        };
    }
}
