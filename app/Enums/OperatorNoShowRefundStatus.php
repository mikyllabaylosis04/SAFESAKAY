<?php

namespace App\Enums;

enum OperatorNoShowRefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Refunded = 'refunded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Refunded => 'Refunded',
            self::Rejected => 'Rejected',
        };
    }

    public function workflowLabel(): string
    {
        return match ($this) {
            self::Pending => 'Refund Requested',
            self::Approved, self::Processing => 'Refund Processing',
            self::Refunded => 'Refunded',
            self::Rejected => 'Rejected',
        };
    }
}
