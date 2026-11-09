<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Models\BoatBooking;
use App\Models\RentalPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

class RentalPaymentService
{
    public function userMayVerify(
        User $user,
        BoatBooking $booking
    ): bool {
        $booking->snapshotGcashPayee();

        if ($booking->paysOperatorGcash()) {
            if (! $user->isMobileOperator()) {
                return false;
            }

            $booking->loadMissing('boat.operator');

            return (int) $booking->boat?->operator?->user_id
                === (int) $user->id;
        }

        return $user->isAdministrator()
            && (int) $user->organization_id
                === (int) $booking->organization_id
            && ! request()->is('api/*');
    }

    public function userMayRecordBalance(
        User $user,
        BoatBooking $booking
    ): bool {
        if (! $user->isMobileOperator()) {
            return false;
        }

        $booking->loadMissing('boat.operator');

        return (int) $booking->boat?->operator?->user_id
            === (int) $user->id;
    }

    /**
     * Manual GCash verification.
     *
     * This confirmation is required only for
     * payments made directly to a barangay
     * or operator GCash account.
     *
     * Xendit test payments are confirmed by webhook.
     *
     * @param array<string, mixed> $input
     */
    public function assertGcashHistoryConfirmed(
        array $input
    ): void {
        $confirmed = filter_var(
            $input['confirmed_gcash_transaction'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirmed_gcash_transaction' => [
                    'Confirm that you checked the actual GCash transaction history before verifying.',
                ],
            ]);
        }
    }

    public function authorizeVerifier(
        User $user,
        BoatBooking $booking
    ): void {
        $booking->snapshotGcashPayee();

        if ($booking->paysOperatorGcash()) {
            abort_unless(
                $user->isMobileOperator(),
                403,
                'Only the assigned boat operator can verify payments sent to operator GCash.',
            );

            $booking->loadMissing('boat.operator');

            abort_unless(
                (int) $booking->boat?->operator?->user_id
                    === (int) $user->id,
                403,
                'Only the assigned boat operator who received this GCash payment can verify it.',
            );

            return;
        }

        if ($user->isMobileOperator()) {
            abort(
                403,
                'You cannot verify this payment. The passenger paid the barangay GCash account. Only a barangay administrator can verify it in the admin portal.',
            );
        }

        abort_unless(
            $user->isAdministrator(),
            403,
            'Only a barangay administrator can verify payments sent to barangay GCash.',
        );

        abort_unless(
            (int) $user->organization_id
                === (int) $booking->organization_id,
            403,
            'You can only verify payments for your own barangay organization.',
        );

        if (request()->is('api/*')) {
            abort(
                403,
                'Verify barangay down payments in the SafeSakay admin website, not the mobile API.',
            );
        }
    }

    public function authorizeBalanceRecorder(
        User $user,
        BoatBooking $booking
    ): void {
        abort_unless(
            $this->userMayRecordBalance($user, $booking),
            403,
            'Only the assigned boat operator can record remaining balance payments.',
        );
    }

    public function verifyPaymentBlockedReason(
        User $user,
        BoatBooking $booking
    ): ?string {
        if (! $booking->canVerifyPayment()) {
            return null;
        }

        if ($this->userMayVerify($user, $booking)) {
            return null;
        }

        if (
            $user->isMobileOperator() &&
            $booking->paysBarangayGcash()
        ) {
            return 'You cannot verify this payment. The passenger paid the barangay GCash account. Only a barangay administrator can verify it in the admin portal.';
        }

        if (
            $user->isMobileOperator() &&
            $booking->paysOperatorGcash()
        ) {
            return 'Only the assigned boat operator who received this GCash payment can verify it.';
        }

        if (
            $user->isAdministrator() &&
            request()->is('api/*')
        ) {
            return 'Verify barangay down payments in the SafeSakay admin website, not the mobile API.';
        }

        return 'You are not authorized to verify this payment.';
    }

    /**
     * Verify a manual GCash downpayment.
     *
     * Existing SafeSakay functionality.
     *
     * @param array<string, mixed> $input
     */
    public function verify(
        BoatBooking $booking,
        User $user,
        array $input = []
    ): BoatBooking {
        $this->authorizeVerifier($user, $booking);
        $this->assertGcashHistoryConfirmed($input);

        return DB::transaction(function () use (
            $booking,
            $user
        ) {
            $record = BoatBooking::withoutGlobalScope(
                'organization'
            )
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $this->authorizeVerifier($user, $record);

            if (! $record->canVerifyPayment()) {
                throw ValidationException::withMessages([
                    'payment' => [
                        $record->paymentStatus()
                            === BookingPaymentStatus::Verified
                                ? 'This payment has already been verified.'
                                : 'This rental does not have a down payment waiting for verification.',
                    ],
                ]);
            }

            $record->update([
                'payment_status' =>
                    BookingPaymentStatus::Verified,

                'payment_verified_at' => now(),

                'payment_verified_by' => $user->id,

                'payment_rejection_reason' => null,

                'status' => BoatBookingStatus::Confirmed,
            ]);

            return $record->fresh();
        });
    }

    /**
     * Reject a manual GCash payment.
     */
    public function reject(
        BoatBooking $booking,
        User $user,
        string $reason
    ): BoatBooking {
        $this->authorizeVerifier($user, $booking);

        return DB::transaction(function () use (
            $booking,
            $user,
            $reason
        ) {
            $record = BoatBooking::withoutGlobalScope(
                'organization'
            )
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $this->authorizeVerifier($user, $record);

            if (
                $record->status
                    !== BoatBookingStatus::AwaitingPayment
                ||
                $record->paymentStatus()
                    === BookingPaymentStatus::Verified
            ) {
                throw ValidationException::withMessages([
                    'reason' => [
                        'This payment can no longer be rejected.',
                    ],
                ]);
            }

            if (
                $record->paymentStatus()
                    !== BookingPaymentStatus::ForVerification
            ) {
                throw ValidationException::withMessages([
                    'reason' => [
                        'There is no payment proof waiting for verification.',
                    ],
                ]);
            }

            $record->update([
                'payment_status' =>
                    BookingPaymentStatus::Rejected,

                'payment_rejection_reason' =>
                    trim($reason),
            ]);

            $record->recordActivity(
                'payment_rejected',
                $user
            );

            return $record->fresh();
        });
    }

    /**
     * Record a remaining balance payment.
     *
     * Existing operator cash/GCash functionality.
     */
    public function recordBalance(
        BoatBooking $booking,
        User $user,
        string $method,
        ?string $reference,
    ): BoatBooking {
        $this->authorizeBalanceRecorder(
            $user,
            $booking
        );

        if (
            $method === 'gcash' &&
            ! filled($reference)
        ) {
            throw ValidationException::withMessages([
                'reference' => [
                    'Enter the GCash transaction reference number.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $booking,
            $user,
            $method,
            $reference
        ) {
            $record = BoatBooking::withoutGlobalScope(
                'organization'
            )
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $this->authorizeBalanceRecorder(
                $user,
                $record
            );

            if (! $record->canRecordBalancePayment()) {
                throw ValidationException::withMessages([
                    'method' => [
                        $record->balanceIsSettled()
                            && $record->hasOutstandingBalance()
                                ? 'The remaining balance has already been recorded as paid.'
                                : 'This rental is not ready for a remaining balance payment.',
                    ],
                ]);
            }

            $record->update([
                'balance_status' =>
                    BookingBalanceStatus::Paid,

                'balance_method' => $method,

                'balance_reference' =>
                    $method === 'gcash'
                        ? trim((string) $reference)
                        : null,

                'balance_paid_at' => now(),

                'balance_recorded_by' => $user->id,
            ]);

            $record->recordActivity(
                'balance_paid',
                $user
            );

            return $record->fresh();
        });
    }

    /**
     * Apply a verified Xendit downpayment.
     */
    public function applyVerifiedHostedDownpayment(
        BoatBooking $booking,
        RentalPayment $payment,
        string $provider,
    ): bool {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Online booking synchronization requires a database transaction.'
            );
        }

        if (
            (int) $payment->boat_booking_id !== (int) $booking->id
            || (int) $payment->organization_id !== (int) $booking->organization_id
        ) {
            Log::warning(
                'Online payment and booking do not match.',
                [
                    'rental_payment_id' => $payment->id,
                    'boat_booking_id' => $booking->id,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        if (
            $payment->provider !== $provider
            || ! $payment->isPaid()
            || ! filled($payment->provider_payment_id)
            || $payment->paid_at === null
            || $payment->currency !== 'PHP'
        ) {
            return false;
        }

        if ($payment->payment_type !== 'downpayment') {
            Log::warning(
                'Online payment requires further reconciliation.',
                [
                    'rental_payment_id' => $payment->id,
                    'payment_type' => $payment->payment_type,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
        ], true)) {
            Log::warning(
                'Online payment received for inactive booking.',
                [
                    'rental_payment_id' => $payment->id,
                    'boat_booking_id' => $booking->id,
                    'status' => $booking->status->value,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::Verified) {
            return in_array($booking->status, [
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
                BoatBookingStatus::Completed,
            ], true);
        }

        if (
            $booking->status !== BoatBookingStatus::AwaitingPayment
            || ! $booking->canSubmitPaymentProof()
            || $booking->hasPaymentSubmission()
        ) {
            Log::warning(
                'Online payment cannot automatically confirm booking.',
                [
                    'rental_payment_id' => $payment->id,
                    'boat_booking_id' => $booking->id,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        $expectedAmount = (int) round(
            (float) $booking->down_payment_amount * 100
        );

        if ($expectedAmount <= 0 || (int) $payment->amount !== $expectedAmount) {
            Log::warning(
                'Online downpayment amount mismatch.',
                [
                    'rental_payment_id' => $payment->id,
                    'boat_booking_id' => $booking->id,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        $booking->update([
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => $payment->paid_at,
            'payment_verified_by' => null,
            'payment_rejection_reason' => null,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        $booking->recordActivity(
            'xendit_payment_verified',
            actorName: 'Xendit',
            metadata: [
                'rental_payment_id' => $payment->id,
                'provider_payment_id' => $payment->provider_payment_id,
            ],
        );

        return true;
    }

    public function applyVerifiedHostedBalancePayment(
        BoatBooking $booking,
        RentalPayment $payment,
        string $provider,
    ): bool {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Online balance synchronization requires a database transaction.'
            );
        }

        if (
            $payment->provider !== $provider
            || $payment->payment_type !== 'full_payment'
            || ! $payment->isPaid()
            || ! filled($payment->provider_payment_id)
            || $payment->paid_at === null
            || $payment->currency !== 'PHP'
        ) {
            return false;
        }

        if (
            (int) $payment->boat_booking_id !== (int) $booking->id
            || (int) $payment->organization_id !== (int) $booking->organization_id
        ) {
            return false;
        }

        if ($booking->paymentStatus() !== BookingPaymentStatus::Verified) {
            return false;
        }

        if (! $booking->canRecordBalancePayment() && ! $booking->balanceIsSettled()) {
            if ($booking->balanceIsSettled()) {
                return true;
            }

            return false;
        }

        $expectedAmount = (int) round((float) $booking->remaining_balance * 100);

        if ($expectedAmount <= 0 || (int) $payment->amount !== $expectedAmount) {
            Log::warning(
                'Online balance amount mismatch.',
                [
                    'rental_payment_id' => $payment->id,
                    'boat_booking_id' => $booking->id,
                    'provider' => $provider,
                ]
            );

            return false;
        }

        if ($booking->balanceIsSettled()) {
            return true;
        }

        $booking->update([
            'balance_status' => BookingBalanceStatus::Paid,
            'balance_method' => 'gcash',
            'balance_reference' => $payment->provider_payment_id,
            'balance_paid_at' => $payment->paid_at,
            'balance_recorded_by' => null,
        ]);

        $booking->recordActivity(
            $provider === 'xendit' ? 'xendit_balance_paid' : 'online_balance_paid',
            actorName: $provider === 'xendit' ? 'Xendit' : 'Online Payment',
            metadata: [
                'rental_payment_id' => $payment->id,
                'provider_payment_id' => $payment->provider_payment_id,
            ],
        );

        return true;
    }

    public function applyVerifiedXenditDownpayment(
        BoatBooking $booking,
        RentalPayment $payment
    ): bool {
        return $this->applyVerifiedHostedDownpayment($booking, $payment, 'xendit');
    }

    public function applyVerifiedXenditBalancePayment(
        BoatBooking $booking,
        RentalPayment $payment
    ): bool {
        return $this->applyVerifiedHostedBalancePayment($booking, $payment, 'xendit');
    }

    public function applyVerifiedXenditFullRental(
        BoatBooking $booking,
        RentalPayment $payment
    ): bool {
        return $this->applyVerifiedHostedFullRental($booking, $payment, 'xendit');
    }

    public function applyVerifiedHostedFullRental(
        BoatBooking $booking,
        RentalPayment $payment,
        string $provider,
    ): bool {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Online full rental synchronization requires a database transaction.'
            );
        }

        if (
            (int) $payment->boat_booking_id !== (int) $booking->id
            || (int) $payment->organization_id !== (int) $booking->organization_id
        ) {
            return false;
        }

        if (
            $payment->provider !== $provider
            || $payment->payment_type !== 'full_rental'
            || ! $payment->isPaid()
            || ! filled($payment->provider_payment_id)
            || $payment->paid_at === null
            || $payment->currency !== 'PHP'
        ) {
            return false;
        }

        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
        ], true)) {
            return false;
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::Verified && $booking->balanceIsSettled()) {
            return in_array($booking->status, [
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
                BoatBookingStatus::Completed,
            ], true);
        }

        if (
            $booking->status !== BoatBookingStatus::AwaitingPayment
            || ! $booking->canSubmitPaymentProof()
            || $booking->hasPaymentSubmission()
        ) {
            return false;
        }

        $expectedAmount = (int) round((float) $booking->rental_price * 100);

        if ($expectedAmount <= 0 || (int) $payment->amount !== $expectedAmount) {
            Log::warning('Online full rental amount mismatch.', [
                'rental_payment_id' => $payment->id,
                'boat_booking_id' => $booking->id,
                'provider' => $provider,
            ]);

            return false;
        }

        $booking->update([
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => $payment->paid_at,
            'payment_verified_by' => null,
            'payment_rejection_reason' => null,
            'status' => BoatBookingStatus::Confirmed,
            'remaining_balance' => '0.00',
            'balance_status' => BookingBalanceStatus::Paid,
            'balance_method' => 'gcash',
            'balance_reference' => $payment->provider_payment_id,
            'balance_paid_at' => $payment->paid_at,
            'balance_recorded_by' => null,
        ]);

        $booking->recordActivity(
            $provider === 'xendit' ? 'xendit_full_rental_paid' : 'online_full_rental_paid',
            actorName: $provider === 'xendit' ? 'Xendit' : 'Online Payment',
            metadata: [
                'rental_payment_id' => $payment->id,
                'provider_payment_id' => $payment->provider_payment_id,
            ],
        );

        return true;
    }

    /**
     * Get the existing booking GCash payee.
     *
     * This is used by the manual payment workflow.
     */
    public function payeeFor(
        BoatBooking $booking
    ): GcashPayee {
        return $booking->gcashPayee();
    }
}
