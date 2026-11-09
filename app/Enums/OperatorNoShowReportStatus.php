<?php

namespace App\Enums;

enum OperatorNoShowReportStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Dismissed = 'dismissed';
    case Delayed = 'delayed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Verification',
            self::Verified => 'Verified',
            self::Dismissed => 'Rejected',
            self::Delayed => 'Delayed',
        };
    }
}
