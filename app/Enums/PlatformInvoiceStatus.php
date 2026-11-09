<?php

namespace App\Enums;

enum PlatformInvoiceStatus: string
{
    case Due = 'due';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Waived = 'waived';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Due => 'Due',
            self::AwaitingVerification => 'Awaiting Verification',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Waived => 'Waived',
            self::Overdue => 'Overdue / Locked',
        };
    }
}
