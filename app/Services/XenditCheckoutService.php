<?php

namespace App\Services;

use App\Models\BoatBooking;
use App\Models\RentalPayment;
use App\Support\XenditMoney;
use Illuminate\Support\Facades\DB;
use Throwable;

class XenditCheckoutService extends HostedRentalCheckoutService
{
    public function __construct()
    {
        parent::__construct('xendit');
    }

    /**
     * Record a finished GCash checkout when the phone returns, even if the webhook has not arrived.
     */
    public function syncPendingFromXendit(
        BoatBooking $booking,
        XenditService $xendit,
        RentalPaymentService $payments,
    ): void {
        if (! config('services.xendit.enabled', false)) {
            return;
        }

        $pending = RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', 'xendit')
            ->where('status', 'pending')
            ->whereNotNull('checkout_session_id')
            ->orderByDesc('id')
            ->first();

        if (! $pending || ! is_string($pending->checkout_session_id) || $pending->checkout_session_id === '') {
            return;
        }

        try {
            $session = $xendit->getPaymentRequest($pending->checkout_session_id);
        } catch (Throwable) {
            return;
        }

        $status = strtoupper((string) ($session['status'] ?? ''));

        if (in_array($status, ['EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED'], true)) {
            $pending->update([
                'status' => 'expired',
                'checkout_url' => null,
            ]);

            return;
        }

        if (! in_array($status, ['COMPLETED', 'SUCCEEDED'], true)) {
            return;
        }

        $amount = XenditMoney::toMinorFromApiAmount($session['request_amount'] ?? $session['amount'] ?? 0);
        $providerPaymentId = $this->providerPaymentId($session, $pending->checkout_session_id);

        if ($amount <= 0 || $amount !== (int) $pending->amount || strtoupper((string) ($session['currency'] ?? 'PHP')) !== 'PHP') {
            return;
        }

        DB::transaction(function () use ($booking, $pending, $amount, $providerPaymentId, $payments): void {
            $record = BoatBooking::withoutGlobalScope('organization')
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            $payment = RentalPayment::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->first();

            if (! $record || ! $payment || $payment->status !== 'pending' || (int) $payment->amount !== $amount) {
                return;
            }

            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId,
                'paid_at' => now(),
            ]);

            $payment = $payment->fresh();

            if (! $payment) {
                return;
            }

            $confirmed = match ($payment->payment_type) {
                'full_payment' => $payments->applyVerifiedXenditBalancePayment($record, $payment),
                'full_rental' => $payments->applyVerifiedXenditFullRental($record, $payment),
                default => $payments->applyVerifiedXenditDownpayment($record, $payment),
            };

            if (! $confirmed && in_array($payment->payment_type, ['downpayment', 'full_rental'], true)) {
                $payment->update([
                    'status' => 'requires_reconciliation',
                ]);
            }
        });

        $booking->refresh();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function providerPaymentId(array $session, string $fallback): string
    {
        foreach (['payment_id', 'latest_payment_request_id', 'payment_request_id'] as $key) {
            $value = $session[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }
}
