<?php

namespace App\Enums;

enum CommissionSettlementStatus: string
{
    case PendingSettlement = 'pending_settlement';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case CollectedWithRental = 'collected_with_rental';
    case Rejected = 'rejected';
    case Overdue = 'overdue';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::PendingSettlement => 'Pending Settlement',
            self::AwaitingVerification => 'Awaiting Verification',
            self::Paid => 'Paid',
            self::CollectedWithRental => 'Collected with rental payment',
            self::Rejected => 'Rejected',
            self::Overdue => 'Overdue Settlement',
            self::Waived => 'Waived — trip did not happen',
        };
    }

    public function isReceived(): bool
    {
        return in_array($this, [self::Paid, self::CollectedWithRental], true);
    }
}
