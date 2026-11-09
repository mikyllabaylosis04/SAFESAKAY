<?php

namespace App\Support;

class OnlinePaymentProvider
{
    public static function active(): ?string
    {
        if (config('services.xendit.enabled', false)) {
            return 'xendit';
        }

        return null;
    }

    public static function isEnabled(): bool
    {
        return self::active() !== null;
    }

    /**
     * @return list<string>
     */
    public static function ledgerProviders(): array
    {
        return ['xendit'];
    }
}
