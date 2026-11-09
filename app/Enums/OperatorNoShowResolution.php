<?php

namespace App\Enums;

enum OperatorNoShowResolution: string
{
    case Reassigned = 'reassigned';
    case RescheduleOffered = 'reschedule_offered';
    case RefundApproved = 'refund_approved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Reassigned => 'Another boat assigned',
            self::RescheduleOffered => 'New date offered — down payment stays',
            self::RefundApproved => 'Down payment will be returned',
            self::Dismissed => 'Report closed',
        };
    }
}
