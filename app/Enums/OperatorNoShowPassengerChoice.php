<?php

namespace App\Enums;

enum OperatorNoShowPassengerChoice: string
{
    case Refund = 'refund';
    case Reschedule = 'reschedule';
    case Replacement = 'replacement';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Full refund requested',
            self::Reschedule => 'Reschedule requested',
            self::Replacement => 'Replacement boat requested',
        };
    }

    public function adminActionLabel(): string
    {
        return match ($this) {
            self::Refund => 'Review & process refund',
            self::Reschedule => 'Review available schedule',
            self::Replacement => 'Find available replacement',
        };
    }
}
