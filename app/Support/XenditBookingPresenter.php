<?php

namespace App\Support;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\XenditCheckoutService;

class XenditBookingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forBooking(BoatBooking $booking): array
    {
        if (! config('services.xendit.enabled', false)) {
            return [
                'enabled' => false,
                'ledger_status' => null,
                'ledger_status_label' => null,
                'checkout_pending' => false,
                'downpayment_method_label' => null,
                'requires_reconciliation' => false,
            ];
        }

        $service = app(XenditCheckoutService::class);
        $pending = $service->activePendingCheckout($booking);
        $paid = $service->paidOnlinePayment($booking, 'downpayment');
        $reconciliation = RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', 'xendit')
            ->where('status', 'requires_reconciliation')
            ->orderByDesc('id')
            ->first();

        $ledger = $reconciliation ?? $paid ?? $pending;

        return [
            'enabled' => true,
            'ledger_status' => $ledger?->status,
            'ledger_status_label' => self::ledgerStatusLabel($ledger),
            'checkout_pending' => $pending !== null,
            'checkout_expires_at_label' => $pending?->checkout_expires_at
                ?->timezone('Asia/Manila')
                ->format('M d, Y · g:i A'),
            'downpayment_method_label' => self::downPaymentMethodLabel(
                $booking,
                $paid,
                $pending,
            ),
            'requires_reconciliation' => $reconciliation !== null,
            'provider_payment_id' => $paid?->provider_payment_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mobilePayload(BoatBooking $booking): array
    {
        $enabled = (bool) config('services.xendit.enabled', false);
        $presenter = self::forBooking($booking);
        $checkoutService = app(XenditCheckoutService::class);
        $pending = $enabled
            ? $checkoutService->activePendingCheckout($booking)
            : null;

        $latestPaid = RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', 'xendit')
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        $receipt = null;

        if ($latestPaid !== null) {
            $receipt = [
                'payment_type' => $latestPaid->payment_type,
                'amount_label' => OrganizationRoute::formatPeso($latestPaid->amount / 100),
                'provider_payment_id' => $latestPaid->provider_payment_id,
                'paid_at_label' => $latestPaid->paid_at
                    ?->timezone('Asia/Manila')
                    ->format('M d, Y · g:i A'),
                'booking_code' => $booking->code,
            ];
        }

        if (! $enabled) {
            return [
                'enabled' => false,
                'can_checkout' => false,
                'checkout_path' => null,
                'checkout_pending' => false,
                'message' => 'Xendit checkout is not enabled.',
            ];
        }

        $canCheckout = $booking->canSubmitPaymentProof()
            && ! $booking->paysOperatorGcash()
            && $booking->paymentStatus() !== BookingPaymentStatus::ForVerification
            && ! $checkoutService->hasBlockingPendingCheckout($booking);

        $canBalanceCheckout = $booking->paymentStatus() === BookingPaymentStatus::Verified
            && $booking->canRecordBalancePayment()
            && ! $checkoutService->hasBlockingPendingCheckout($booking);

        $configMessage = XenditRedirectUrls::configurationMessage();
        $serverBlocksCheckout = $configMessage !== null && $pending === null;

        if ($serverBlocksCheckout) {
            $canCheckout = false;
            $canBalanceCheckout = false;
        }

        return [
            'enabled' => true,
            'can_checkout' => $canCheckout,
            'can_full_rental_checkout' => $canCheckout,
            'can_balance_checkout' => $canBalanceCheckout,
            'checkout_path' => $canCheckout
                ? '/api/bookings/'.$booking->id.'/xendit/checkout'
                : ($canBalanceCheckout
                    ? '/api/bookings/'.$booking->id.'/xendit/checkout'
                    : null),
            'checkout_pending' => $pending !== null,
            'checkout_url' => $pending?->checkout_url,
            'checkout_session_id' => $pending?->checkout_session_id,
            'checkout_expires_at' => $pending?->checkout_expires_at?->toIso8601String(),
            'checkout_payment_type' => $pending?->payment_type,
            'success_return_url' => XenditRedirectUrls::success(),
            'ledger_status' => $presenter['ledger_status'] ?? null,
            'ledger_status_label' => $presenter['ledger_status_label'] ?? null,
            'requires_reconciliation' => (bool) ($presenter['requires_reconciliation'] ?? false),
            'downpayment_method_label' => $presenter['downpayment_method_label'] ?? null,
            'checkout_plans' => XenditCheckoutAmounts::mobilePlans($booking),
            'checkout_policy_version' => RentalCheckoutPolicy::VERSION,
            'payment_receipt' => $receipt,
            'message' => $serverBlocksCheckout
                ? $configMessage
                : ($canCheckout || $canBalanceCheckout
                    ? null
                    : ($pending !== null
                        ? 'Complete your open Xendit checkout or wait for it to expire.'
                        : null)),
        ];
    }

    private static function ledgerStatusLabel(
        ?RentalPayment $payment
    ): ?string {
        return match ($payment?->status) {
            'pending' => 'Xendit Checkout Pending',
            'paid' => 'Xendit Payment Received',
            'expired' => 'Xendit Checkout Expired',
            'requires_reconciliation' => 'Requires Payment Reconciliation',
            default => null,
        };
    }

    private static function downPaymentMethodLabel(
        BoatBooking $booking,
        ?RentalPayment $paid,
        ?RentalPayment $pending,
    ): ?string {
        if ($paid !== null && $booking->paymentStatus() === BookingPaymentStatus::Verified) {
            return 'Xendit GCash';
        }

        if ($pending !== null) {
            return 'Xendit GCash (checkout open)';
        }

        if ($booking->hasPaymentSubmission()) {
            return 'Manual GCash';
        }

        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
        ], true)) {
            return null;
        }

        return null;
    }

    /**
     * Build Xendit customer payload from authenticated user + booking contact.
     *
     * @return array<string, mixed>
     */
    public static function customerPayload(User $user, BoatBooking $booking): array
    {
        $email = trim((string) ($user->email ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid account email is required before starting online payment.');
        }

        $mobile = self::formatPhilippineMobile((string) $booking->contact_number);

        if ($mobile === null) {
            throw new \InvalidArgumentException('A valid contact mobile number is required before starting online payment.');
        }

        $name = trim((string) ($booking->customer_name ?: $user->name ?: 'Passenger'));
        [$given, $surname] = self::splitName($name);

        return [
            'reference_id' => sprintf('SS-U%d-B%d', (int) $user->id, (int) $booking->id),
            'type' => 'INDIVIDUAL',
            'email' => $email,
            'mobile_number' => $mobile,
            'individual_detail' => [
                'given_names' => $given,
                'surname' => $surname,
            ],
        ];
    }

    private static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if ($parts === []) {
            return ['Passenger', 'SafeSakay'];
        }

        if (count($parts) === 1) {
            return [$parts[0], 'SafeSakay'];
        }

        $surname = array_pop($parts);

        return [implode(' ', $parts), (string) $surname];
    }

    private static function formatPhilippineMobile(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+63'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63'.$digits;
        }

        return null;
    }
}
