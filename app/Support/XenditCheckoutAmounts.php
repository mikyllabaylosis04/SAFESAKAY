<?php

namespace App\Support;

use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Services\RentalCommissionService;

final class XenditCheckoutAmounts
{
    /**
     * @return array<string, array<string, mixed>|null>
     */
    public static function mobilePlans(BoatBooking $booking): array
    {
        return [
            'downpayment' => self::plan($booking, 'downpayment'),
            'full_rental' => self::plan($booking, 'full_rental'),
            'full_payment' => self::plan($booking, 'full_payment'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function plan(BoatBooking $booking, string $paymentType): ?array
    {
        $amountMinor = match ($paymentType) {
            'downpayment' => self::toMinorUnits($booking->down_payment_amount),
            'full_rental' => self::toMinorUnits($booking->rental_price),
            'full_payment' => self::toMinorUnits($booking->remaining_balance),
            default => 0,
        };

        if ($amountMinor <= 0) {
            return null;
        }

        $rentalMinor = self::toMinorUnits($booking->rental_price);
        $totalCommissionMinor = self::totalCommissionMinor($booking);
        $commissionMinor = self::commissionForPayment($booking, $paymentType, $amountMinor);

        $operatorMinor = $amountMinor - $commissionMinor;
        $rentalCommissionMinor = $totalCommissionMinor;
        $operatorOnRentalMinor = max(0, $rentalMinor - $rentalCommissionMinor);

        $title = match ($paymentType) {
            'full_rental' => 'Full rental payment',
            'full_payment' => 'Remaining balance',
            default => 'Down payment',
        };

        $paymentTypeLabel = match ($paymentType) {
            'full_rental' => 'Full Rental Payment',
            'full_payment' => 'Remaining Balance',
            default => 'Downpayment',
        };

        $remainingAfterPayMinor = match ($paymentType) {
            'full_rental' => 0,
            'downpayment' => self::toMinorUnits($booking->remaining_balance),
            default => max(0, self::toMinorUnits($booking->remaining_balance) - $amountMinor),
        };

        return [
            'payment_type' => $paymentType,
            'title' => $title,
            'payment_type_label' => $paymentTypeLabel,
            'down_payment_percent_label' => $booking->down_payment_percent !== null
                ? OrganizationRoute::formatPercent((float) $booking->down_payment_percent)
                : null,
            'passenger_pays_minor' => $amountMinor,
            'passenger_pays_label' => OrganizationRoute::formatPeso($amountMinor / 100),
            'amount_to_pay_now_label' => OrganizationRoute::formatPeso($amountMinor / 100),
            'rental_price_label' => OrganizationRoute::formatPeso((float) $booking->rental_price),
            'total_rental_commission_label' => OrganizationRoute::formatPeso($rentalCommissionMinor / 100),
            'operator_share_on_rental_label' => OrganizationRoute::formatPeso($operatorOnRentalMinor / 100),
            'commission_label' => OrganizationRoute::formatPeso($commissionMinor / 100),
            'operator_share_label' => OrganizationRoute::formatPeso($operatorMinor / 100),
            'remaining_balance_label' => OrganizationRoute::formatPeso($remainingAfterPayMinor / 100),
            'commission_included' => (bool) $booking->commission_enabled,
            'commission_percentage_label' => $booking->commission_enabled
                ? OrganizationRoute::formatPercent((float) $booking->commission_percentage)
                : null,
        ];
    }

    public static function totalCommissionMinor(BoatBooking $booking): int
    {
        return max(0, (int) round(app(RentalCommissionService::class)->fullRentalCommission($booking) * 100));
    }

    /**
     * Commission is a share of the full rental price, deducted from the first money the
     * barangay receives (the down payment or the full payment).
     */
    public static function commissionForPayment(BoatBooking $booking, string $paymentType, int $amountMinor): int
    {
        $total = self::totalCommissionMinor($booking);

        return match ($paymentType) {
            'full_rental', 'downpayment' => min($amountMinor, $total),
            'full_payment' => min(
                $amountMinor,
                max(0, $total - min(self::toMinorUnits($booking->down_payment_amount), $total)),
            ),
            default => 0,
        };
    }

    private static function toMinorUnits(mixed $amount): int
    {
        if ($amount === null || ! is_numeric($amount)) {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }
}
