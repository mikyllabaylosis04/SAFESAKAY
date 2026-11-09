<?php

namespace App\Enums;

enum OperatorNoShowRescheduleStatus: string
{
    case PendingOperator = 'pending_operator';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingOperator => 'Awaiting Operator Confirmation',
            self::Approved => 'Rescheduled',
            self::Rejected => 'Reschedule Declined',
        };
    }
}
