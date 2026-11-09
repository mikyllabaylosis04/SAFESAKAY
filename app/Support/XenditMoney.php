<?php

namespace App\Support;

/**
 * SafeSakay stores rental payment amounts in PHP centavos (minor units).
 * Xendit Payment Sessions expect the main currency unit (e.g. 500.00 PHP → 500).
 */
final class XenditMoney
{
    public static function toApiAmountFromMinor(int $minorUnits): float
    {
        return round($minorUnits / 100, 2);
    }

    public static function toMinorFromApiAmount(int|float|string $apiAmount): int
    {
        if (! is_numeric($apiAmount)) {
            return 0;
        }

        return (int) round((float) $apiAmount * 100);
    }
}
