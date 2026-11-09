<?php

namespace App\Enums;

enum BoatRegistrationStatus: string
{
    case PendingVerification = 'pending_verification';
    case Registered = 'registered';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Registered => 'Registered',
            self::Rejected => 'Rejected',
        };
    }

    public function allowsAvailability(): bool
    {
        return $this === self::Registered;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases(),
        );
    }
}
