<?php

namespace App\Enums;

enum BoatBookingReplacementStatus: string
{
    case PendingOperator = 'pending_operator';
    case Declined = 'declined';
    case PendingAdminApproval = 'pending_admin_approval';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingOperator => 'Awaiting Replacement Operator',
            self::Declined => 'Replacement Declined',
            self::PendingAdminApproval => 'Pending Admin Approval',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }
}
