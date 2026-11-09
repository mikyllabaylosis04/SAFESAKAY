<?php

namespace App\Enums;

enum BookingPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case ForVerification = 'for_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::ForVerification => 'For Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }
}
