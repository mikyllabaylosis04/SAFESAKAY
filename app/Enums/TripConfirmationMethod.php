<?php

namespace App\Enums;

enum TripConfirmationMethod: string
{
    case PhoneCall = 'phone_call';
    case MobileApp = 'mobile_app';

    public function label(): string
    {
        return match ($this) {
            self::PhoneCall => 'Phone Call',
            self::MobileApp => 'Mobile App',
        };
    }
}
