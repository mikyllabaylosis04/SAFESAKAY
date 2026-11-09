<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\RentalPayment;
use App\Services\RentalPaymentService;
use App\Support\XenditMoney;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class XenditWebhookController extends Controller
{
    public function handle(
        Request $request,
        RentalPaymentService $paymentService
    ): JsonResponse {
        // 1. Verify the Xendit webhook token.
        $expectedToken = trim(
            (string) config('services.xendit.webhook_token', '')
        );

        if ($expectedToken === '') {
            return response()->json([
                'message' => 'Webhook is not configured.',
            ], 503);
        }

        $receivedToken = trim(
            (string) $request->header('x-callback-token', '')
        );

        if (
            $receivedToken === '' ||
            ! hash_equals($expectedToken, $receivedToken)
        ) {
            Log::warning('xendit.webhook.unauthorized');

            return response()->json([
                'message' => 'Unauthorized webhook.',
            ], 401);
        }

        // 2. Validate the basic webhook structure.
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
            ], 400);
        }

        $event = (string) (
            data_get($payload, 'event')
            ?? data_get($payload, 'event_type')
            ?? ''
        );

        $data = data_get($payload, 'data');

        if (! is_array($data)) {
            return response()->json([
                'message' => 'Invalid event data.',
            ], 400);
        }

        // 3. Preserve operator payout synchronization.
        // Support both legacy and v3 payout event names.
        if (in_array($event, [
            'payout.succeeded',
            'payout.failed',
            'payout.reversed',
            'v3_payout.succeeded',
            'v3_payout.failed',
            'v3_payout.reversed',
        ], true)) {
            $payoutId = data_get($data, 'payout_id')
                ?? data_get($data, 'id');

            $payoutStatus = (string) (
                data_get($data, 'status')
                ?: (
                    str_ends_with($event, '.succeeded')
                        ? 'SUCCEEDED'
                        : (
                            str_ends_with($event, '.reversed')
                                ? 'REVERSED'
                                : 'FAILED'
                        )
                )
            );

            if (! is_string($payoutId) || $payoutId === '') {
                return response()->json([
                    'received' => true,
                    'ignored' => true,
                ]);
            }

            try {
                app(
                    \App\Services\OperatorGcashPayoutService::class
                )->syncByPayoutId(
                    $payoutId,
                    $payoutStatus
                );
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Temporary payout processing error.',
                ], 503);
            }

            return response()->json([
                'received' => true,
                'payout_synced' => true,
            ]);
        }

        // Passenger refunds (rental cancellations approved by the barangay admin).
        if (in_array($event, ['refund.succeeded', 'refund.failed'], true)) {
            try {
                $matched = app(\App\Services\RentalRefundService::class)->applyWebhook($data);
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Temporary refund processing error.',
                ], 503);
            }

            return response()->json([
                'received' => true,
                'refund_synced' => $matched,
            ]);
        }

        // 4. Handle expired or cancelled checkout attempts.
        if (in_array($event, [
            'payment_session.expired',
            'payment_session.cancelled',
            'payment_session.canceled',
            'payment_request.expiry',
            'payment_request.expired',
        ], true)) {
            return $this->handleSessionClosed(
                $data,
                'expired',
                $event
            );
        }

        // 5. Handle supported successful payment events.
        $status = strtoupper(
            trim((string) data_get($data, 'status', ''))
        );

        $isSessionCompleted = (
            $event === 'payment_session.completed'
            && in_array($status, [
                '',
                'COMPLETED',
                'SUCCEEDED',
            ], true)
        );

        $isPaymentCaptured = (
            in_array($event, [
                'payment.capture',
                'payment.succeeded',
            ], true)
            && in_array($status, [
                'SUCCEEDED',
                'COMPLETED',
                'CAPTURED',
            ], true)
        );

        if (! $isSessionCompleted && ! $isPaymentCaptured) {
            return response()->json([
                'received' => true,
                'ignored' => true,
            ]);
        }

        // 6. Find supported payment-request/session IDs.
        $sessionIds = $this->sessionIdsFromPayload(
            $data,
            $event
        );

        if ($sessionIds === []) {
            return response()->json([
                'message' => 'Invalid payment reference.',
            ], 422);
        }

        // IMPORTANT FIX:
        // Match a SafeSakay payment BEFORE validating currency
        // and amount. Xendit's dashboard sample may use IDR/CARDS.
        $existing = $this->findExistingPayment($sessionIds);

        if (! $existing) {
            $incomingCurrency = $this->currencyFromPayload(
                $data
            );

            Log::warning('xendit.webhook.unmatched_payment', [
                'event' => $event,
                'currency' => $incomingCurrency,
            ]);

            // Non-PHP sample/test events must not change
            // any SafeSakay booking or commission record.
            if ($incomingCurrency !== 'PHP') {
                return response()->json([
                    'received' => true,
                    'ignored' => true,
                    'reason' => 'No matching SafeSakay payment.',
                ]);
            }

            // A PHP payment may be a real SafeSakay test
            // payment whose local checkout record has not
            // been committed yet. Ask Xendit to retry.
            return response()->json([
                'message' => 'Payment attempt not yet registered.',
            ], 503);
        }

        $sessionId = (string) $existing->checkout_session_id;

        // 7. Validate matching payment details strictly.
        $rawAmount = $this->amountFromPayload($data);

        if ($rawAmount === null) {
            Log::warning('xendit.webhook.invalid_amount', [
                'event' => $event,
                'checkout_session_id' => $sessionId,
            ]);

            return response()->json([
                'message' => 'Invalid paid session details.',
            ], 422);
        }

        $amount = XenditMoney::toMinorFromApiAmount(
            $rawAmount
        );

        $currency = $this->currencyFromPayload($data);

        $providerPaymentId = $this->providerPaymentIdFromPayload(
            $data
        );

        if (
            $amount <= 0
            || $currency !== 'PHP'
            || $providerPaymentId === null
        ) {
            Log::warning('xendit.webhook.invalid_paid_details', [
                'event' => $event,
                'checkout_session_id' => $sessionId,
                'amount_minor' => $amount,
                'currency' => $currency,
                'has_provider_payment_id' => (
                    $providerPaymentId !== null
                ),
            ]);

            return response()->json([
                'message' => 'Invalid paid session details.',
            ], 422);
        }

        // 8. Check explicit provider environment when supplied.
        // Payment Session "mode" can be PAYMENT_LINK, so it
        // must NOT be used as a test/live environment indicator.
        if (config('services.xendit.test_mode_only', true)) {
            $environment = data_get($payload, 'environment')
                ?? data_get($data, 'environment');

            if (
                $environment !== null
                && ! in_array(
                    strtolower((string) $environment),
                    ['test', 'development', 'sandbox'],
                    true
                )
            ) {
                Log::warning(
                    'xendit.webhook.non_test_event_rejected'
                );

                return response()->json([
                    'message' => 'Only test payments are supported.',
                ], 403);
            }
        }

        $paidAt = $this->paidAtFromPayload($data);

        // 9. Record verified payment within a DB transaction.
        try {
            return DB::transaction(function () use (
                $sessionId,
                $providerPaymentId,
                $amount,
                $paidAt,
                $paymentService,
                $existing
            ): JsonResponse {

                $booking = BoatBooking::withoutGlobalScope(
                    'organization'
                )
                    ->whereKey($existing->boat_booking_id)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    return response()->json([
                        'message' => 'Booking not found.',
                    ], 409);
                }

                $payment = RentalPayment::query()
                    ->whereKey($existing->id)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return response()->json([
                        'message' => 'Payment record unavailable.',
                    ], 503);
                }

                // Ensure this payment really belongs
                // to the correct booking and organization.
                if (
                    $payment->provider !== 'xendit'
                    || $payment->checkout_session_id !== $sessionId
                    || (int) $payment->boat_booking_id
                        !== (int) $booking->id
                    || (int) $payment->organization_id
                        !== (int) $booking->organization_id
                ) {
                    return response()->json([
                        'message' => 'Payment reconciliation failed.',
                    ], 409);
                }

                // Prevent incorrect or tampered amounts.
                if (
                    (int) $payment->amount !== $amount
                    || $payment->currency !== 'PHP'
                ) {
                    return response()->json([
                        'message' => 'Payment amount mismatch.',
                    ], 409);
                }

                // Duplicate webhook: do not record another payment.
                if ($payment->isPaid()) {
                    if (
                        $payment->provider_payment_id
                        !== $providerPaymentId
                    ) {
                        return response()->json([
                            'message' => 'Payment reference conflict.',
                        ], 409);
                    }

                    $confirmed = $this->applyBookingOutcome(
                        $paymentService,
                        $booking,
                        $payment
                    );

                    return response()->json([
                        'received' => true,
                        'already_processed' => true,
                        'booking_confirmed' => $confirmed,
                    ]);
                }

                if ($payment->status !== 'pending') {
                    return response()->json([
                        'message' => 'Payment requires reconciliation.',
                    ], 409);
                }

                // Check whether the provider payment ID
                // has already been recorded elsewhere.
                $duplicate = RentalPayment::query()
                    ->where(
                        'provider_payment_id',
                        $providerPaymentId
                    )
                    ->where('id', '!=', $payment->id)
                    ->exists();

                if ($duplicate) {
                    return response()->json([
                        'message' => 'Duplicate payment reference.',
                    ], 409);
                }

                $payment->update([
                    'status' => 'paid',
                    'provider_payment_id' => $providerPaymentId,
                    'paid_at' => $paidAt,
                ]);

                // Preserve existing SafeSakay booking logic.
                $confirmed = $this->applyBookingOutcome(
                    $paymentService,
                    $booking,
                    $payment->fresh()
                );

                if (
                    ! $confirmed
                    && in_array(
                        $payment->payment_type,
                        ['downpayment', 'full_rental'],
                        true
                    )
                ) {
                    $payment->update([
                        'status' => 'requires_reconciliation',
                    ]);
                }

                return response()->json([
                    'received' => true,
                    'payment_recorded' => true,
                    'booking_confirmed' => $confirmed,
                ]);
            }, 3);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Temporary webhook processing error.',
            ], 503);
        }
    }

    /**
     * Handle expiry/cancellation without modifying paid records.
     *
     * @param array<string, mixed> $data
     */
    private function handleSessionClosed(
        array $data,
        string $status,
        string $event
    ): JsonResponse {
        $sessionIds = $this->sessionIdsFromPayload(
            $data,
            $event
        );

        $existing = $this->findExistingPayment($sessionIds);

        if (! $existing) {
            return response()->json([
                'received' => true,
                'ignored' => true,
            ]);
        }

        RentalPayment::query()
            ->whereKey($existing->id)
            ->where('provider', 'xendit')
            ->where('status', 'pending')
            ->update([
                'status' => $status,
                'checkout_url' => null,
            ]);

        return response()->json([
            'received' => true,
            'session_closed' => true,
        ]);
    }

    /**
     * Return candidate IDs, prioritizing the correct API family.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function sessionIdsFromPayload(
        array $data,
        string $event
    ): array {
        $keys = str_starts_with(
            $event,
            'payment_session.'
        )
            ? [
                'payment_session_id',
                'payment_request_id',
                'latest_payment_request_id',
            ]
            : [
                'payment_request_id',
                'latest_payment_request_id',
                'payment_session_id',
            ];

        $ids = [];

        foreach ($keys as $key) {
            $value = data_get($data, $key);

            if (
                is_string($value)
                && trim($value) !== ''
            ) {
                $ids[] = trim($value);
            }
        }

        $id = data_get($data, 'id');

        if (
            is_string($id)
            && (
                str_starts_with($id, 'ps-')
                || str_starts_with($id, 'pr-')
            )
        ) {
            $ids[] = trim($id);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Match against locally stored Xendit checkout identifiers.
     *
     * @param array<int, string> $sessionIds
     */
    private function findExistingPayment(
        array $sessionIds
    ): ?RentalPayment {
        foreach ($sessionIds as $sessionId) {
            $payment = RentalPayment::query()
                ->where('provider', 'xendit')
                ->where('checkout_session_id', $sessionId)
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function amountFromPayload(
        array $data
    ): ?float {
        $candidates = [
            data_get($data, 'request_amount'),
            data_get($data, 'amount'),
            data_get($data, 'captures.0.capture_amount'),
            data_get($data, 'payment_details.request_amount'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function currencyFromPayload(
        array $data
    ): ?string {
        $currency = data_get($data, 'currency');

        if (
            is_string($currency)
            && trim($currency) !== ''
        ) {
            return strtoupper(trim($currency));
        }

        if (
            strtoupper(
                (string) data_get($data, 'country', '')
            ) === 'PH'
        ) {
            return 'PHP';
        }

        return null;
    }

    /**
     * A successful payment must have a real provider payment ID.
     *
     * @param array<string, mixed> $data
     */
    private function providerPaymentIdFromPayload(
        array $data
    ): ?string {
        $candidates = [
            data_get($data, 'payment_id'),
            data_get($data, 'latest_payment_request_id'),
            data_get($data, 'payment_request_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function paidAtFromPayload(
        array $data
    ): Carbon {
        $value = data_get($data, 'updated')
            ?? data_get($data, 'created')
            ?? data_get($data, 'paid_at');

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // Use server time as fallback.
            }
        }

        return now();
    }

    /**
     * Preserve the existing SafeSakay payment outcomes.
     */
    private function applyBookingOutcome(
        RentalPaymentService $paymentService,
        BoatBooking $booking,
        RentalPayment $payment
    ): bool {
        if ($payment->payment_type === 'full_payment') {
            return $paymentService
                ->applyVerifiedXenditBalancePayment(
                    $booking,
                    $payment
                );
        }

        if ($payment->payment_type === 'full_rental') {
            return $paymentService
                ->applyVerifiedXenditFullRental(
                    $booking,
                    $payment
                );
        }

        return $paymentService
            ->applyVerifiedXenditDownpayment(
                $booking,
                $payment
            );
    }
}
