<?php

namespace App\Support;

use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\RentalPayment;
use Illuminate\Support\Carbon;

/**
 * Builds the list of payment lines (downpayment, full, balance) recorded for a rental.
 * Shared by the operator transactions screen and the passenger payment history.
 */
class RentalPaymentLedger
{
    /**
     * @return list<array<string, mixed>>
     */
    public function lines(BoatBooking $booking): array
    {
        $lines = [];
        $reversed = $booking->commission?->commission_reversed_at !== null;

        $payments = $booking->rentalPayments->filter(fn ($payment) => $payment instanceof RentalPayment);
        $hasLivePayment = $payments->contains(fn (RentalPayment $payment) => $payment->isPaid() || $payment->isPending());
        // Retried or abandoned checkouts are not payments: only the last failed attempt of a still-unpaid rental is listed.
        $lastFailedId = $hasLivePayment
            ? null
            : $payments->filter(fn (RentalPayment $payment) => $payment->isExpired())->sortByDesc('id')->first()?->id;

        foreach ($payments as $payment) {
            if ($payment->isExpired() ? $payment->id !== $lastFailedId : (! $payment->isPaid() && ! $payment->isPending())) {
                continue;
            }

            $refunded = $payment->isPaid() && $payment->isRefunded()
                ? min((int) $payment->amount, (int) ($payment->refunded_amount ?? $payment->amount))
                : 0;
            $kept = (int) $payment->amount - $refunded;
            $method = $payment->provider === 'xendit' ? 'GCash via Xendit' : 'Online payment';
            $transactionId = $payment->provider_payment_id ?: $payment->checkout_session_id;

            if ($payment->isPaid() && $refunded > 0 && $kept > 0 && ! $reversed) {
                // Passenger cancellation of a full payment: the downpayment stays, the rest was refunded.
                $lines[] = [...$this->line($booking, 'payment-'.$payment->id, $this->paymentKind($payment->payment_type), round($kept / 100, 2), $method, 'verified', $payment->paid_at, $transactionId, $payment->provider), 'partially_refunded' => true];
                $lines[] = $this->line($booking, 'payment-'.$payment->id.'-refund', $this->paymentKind($payment->payment_type), round($refunded / 100, 2), $method, 'refunded', $payment->refunded_at ?? $payment->paid_at, $transactionId, $payment->provider);

                continue;
            }

            $status = $payment->isPaid()
                ? ($reversed || $refunded > 0 ? 'refunded' : 'verified')
                : ($payment->isExpired() ? 'failed' : 'pending');

            $lines[] = $this->line(
                $booking,
                'payment-'.$payment->id,
                $this->paymentKind($payment->payment_type),
                round($payment->amount / 100, 2),
                $method,
                $status,
                $payment->isPaid() ? $payment->paid_at : $payment->created_at,
                $transactionId,
                $payment->provider,
            );
        }

        $hasVerifiedDownOrFull = collect($lines)->contains(
            fn (array $line) => $line['payment_status'] === 'verified'
                && in_array($line['payment_type'], ['downpayment', 'full'], true),
        );
        $hasVerifiedBalance = collect($lines)->contains(
            fn (array $line) => $line['payment_status'] === 'verified' && $line['payment_type'] === 'balance',
        );
        $hasVerifiedFull = collect($lines)->contains(
            fn (array $line) => $line['payment_status'] === 'verified' && $line['payment_type'] === 'full',
        );

        if ($booking->paymentStatus() === BookingPaymentStatus::Verified && ! $hasVerifiedDownOrFull) {
            $rental = round((float) ($booking->rental_price ?? 0), 2);
            $down = round((float) ($booking->down_payment_amount ?? 0), 2);
            $isFull = $rental > 0 && abs($down - $rental) < 0.05;
            $lines[] = $this->line(
                $booking,
                'verified-'.$booking->id,
                $isFull ? 'full' : 'downpayment',
                $isFull ? $rental : $down,
                $this->manualMethod($booking, false),
                $reversed ? 'refunded' : 'verified',
                $booking->payment_verified_at,
                $booking->gcash_reference,
                null,
            );
        }

        $rental = round((float) ($booking->rental_price ?? 0), 2);
        $down = round((float) ($booking->down_payment_amount ?? 0), 2);
        $balanceAmount = round(max($rental - $down, 0), 2);
        if ($booking->balanceIsSettled() && ! $hasVerifiedBalance && ! $hasVerifiedFull && $balanceAmount > 0.05 && $booking->balance_paid_at !== null) {
            $lines[] = $this->line(
                $booking,
                'balance-'.$booking->id,
                'balance',
                $balanceAmount,
                $this->manualMethod($booking, true),
                $reversed ? 'refunded' : 'verified',
                $booking->balance_paid_at,
                $booking->balance_reference,
                null,
            );
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::ForVerification && ! collect($lines)->contains(fn (array $line) => $line['payment_status'] === 'pending')) {
            $lines[] = $this->line(
                $booking,
                'pending-'.$booking->id,
                $this->expectedKind($booking),
                round((float) ($booking->payment_amount_submitted ?? $booking->down_payment_amount ?? 0), 2),
                $this->manualMethod($booking, false),
                'pending',
                $booking->payment_submitted_at,
                $booking->gcash_reference,
                null,
            );
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::Rejected && ! collect($lines)->contains(fn (array $line) => $line['payment_status'] === 'failed')) {
            $lines[] = $this->line(
                $booking,
                'failed-'.$booking->id,
                $this->expectedKind($booking),
                round((float) ($booking->payment_amount_submitted ?? $booking->down_payment_amount ?? 0), 2),
                $this->manualMethod($booking, false),
                'failed',
                $booking->payment_submitted_at,
                $booking->gcash_reference,
                null,
            );
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function line(
        BoatBooking $booking,
        string $id,
        string $kind,
        float $amount,
        ?string $method,
        string $status,
        ?Carbon $at,
        ?string $transactionId,
        ?string $provider,
    ): array {
        $sort = $at ?? $booking->booking_date?->copy()->timezone('Asia/Manila');
        $toOperator = $provider === null && ($kind === 'balance' || $booking->paysOperatorGcash());

        return [
            'id' => $id,
            'payment_type' => $kind,
            'payment_type_label' => $this->kindLabel($kind),
            'amount_paid' => round($amount, 2),
            'amount_paid_label' => self::money($amount),
            'payment_method_label' => $method,
            'received_by_label' => $toOperator ? 'Boat operator' : 'SafeSakay',
            'payment_status' => $status,
            'payment_status_label' => $this->statusLabel($status),
            'paid_at' => $at?->toIso8601String(),
            'paid_at_label' => $at ? $at->timezone('Asia/Manila')->format('M j, Y g:i A') : null,
            'transaction_id' => filled($transactionId) ? $transactionId : null,
            'provider' => $provider,
            'sort_at' => $sort?->toIso8601String(),
        ];
    }

    public function expectedKind(BoatBooking $booking): string
    {
        $rental = round((float) ($booking->rental_price ?? 0), 2);
        $down = round((float) ($booking->down_payment_amount ?? 0), 2);

        return $rental > 0 && abs($down - $rental) < 0.05 ? 'full' : 'downpayment';
    }

    public static function money(float $amount): string
    {
        return '₱'.number_format(abs($amount), 2);
    }

    private function paymentKind(?string $type): string
    {
        return match ($type) {
            'full_rental' => 'full',
            'full_payment' => 'balance',
            default => 'downpayment',
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'full' => 'Full Payment',
            'balance' => 'Balance Payment',
            default => 'Downpayment',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'verified' => 'Verified',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'awaiting' => 'Awaiting Payment',
            default => 'Pending',
        };
    }

    private function manualMethod(BoatBooking $booking, bool $balance): string
    {
        $method = $balance ? (string) $booking->balance_method : '';
        if ($method !== '' && ! str_contains(strtolower($method), 'gcash')) {
            return ucfirst($method);
        }

        return 'GCash';
    }
}
