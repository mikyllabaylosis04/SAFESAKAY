<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RentalPayment;
use App\Services\XenditCheckoutService;
use App\Services\XenditGatewayException;
use App\Services\XenditService;
use App\Support\RentalCheckoutPolicy;
use App\Support\XenditBookingPresenter;
use App\Support\XenditMoney;
use App\Support\XenditRedirectUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class XenditCheckoutController extends Controller
{
    /**
     * Create or reuse a Xendit Payment Session checkout.
     *
     * POST /api/bookings/{booking}/xendit/checkout
     */
    public function store(
        Request $request,
        int $booking,
        XenditService $xendit,
        XenditCheckoutService $checkoutService
    ): JsonResponse {
        $user = $request->user();

        if (! $user || $user->isMobileOperator()) {
            abort(403, 'Only passengers can pay for rentals.');
        }

        $data = $request->validate([
            'payment_type' => ['required', Rule::in(['downpayment', 'full_payment', 'full_rental'])],
            'policy_version' => ['required', 'string', 'max:32'],
            'policy_accepted' => ['required', 'boolean', 'accepted'],
            'app_return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $appReturn = XenditRedirectUrls::appReturn($data['app_return_url'] ?? null);

        if (! config('services.xendit.enabled', false)) {
            return response()->json([
                'message' => 'Xendit checkout is not enabled yet.',
            ], 503);
        }

        $configMessage = XenditRedirectUrls::configurationMessage();

        if ($configMessage !== null) {
            return response()->json([
                'message' => $configMessage,
            ], 503);
        }

        $successBase = XenditRedirectUrls::success();
        $cancelBase = XenditRedirectUrls::failure();

        return DB::transaction(function () use (
            $user,
            $booking,
            $data,
            $xendit,
            $checkoutService,
            $successBase,
            $cancelBase,
            $appReturn
        ): JsonResponse {
            $record = BoatBooking::withoutGlobalScope('organization')
                ->whereKey($booking)
                ->lockForUpdate()
                ->firstOrFail();

            $successUrl = $successBase;
            $cancelUrl = $cancelBase.(str_contains($cancelBase, '?') ? '&' : '?').http_build_query(array_filter([
                'booking_id' => $record->id,
                'payment_type' => $data['payment_type'],
                'app_return' => $appReturn,
            ]));

            $checkoutService->expireStaleCheckouts($record);

            if ((int) $record->user_id !== (int) $user->id) {
                abort(403, 'You cannot pay for this booking.');
            }

            if ($data['policy_version'] !== RentalCheckoutPolicy::VERSION) {
                return response()->json([
                    'message' => 'Policy version is outdated. Read and accept the current policy before paying.',
                ], 422);
            }

            if (! RentalCheckoutPolicy::hasCurrentAcceptance($record, $user)) {
                return response()->json([
                    'message' => 'Accept the Cancellation, Refund & No-Show Policy before starting GCash checkout.',
                ], 422);
            }

            $record->loadMissing('organization');
            Organization::abortInactive($record->organization);

            $paymentType = $data['payment_type'];

            if ($paymentType === 'downpayment') {
                $eligibility = $this->assertDownpaymentEligible($record);

                if ($eligibility instanceof JsonResponse) {
                    return $eligibility;
                }

                [$amountMinor, $description, $referenceNumber] = $this->downpaymentCheckoutDetails($record);
            } elseif ($paymentType === 'full_rental') {
                $eligibility = $this->assertFullRentalEligible($record);

                if ($eligibility instanceof JsonResponse) {
                    return $eligibility;
                }

                [$amountMinor, $description, $referenceNumber] = $this->fullRentalCheckoutDetails($record);
            } else {
                $eligibility = $this->assertBalancePaymentEligible($record);

                if ($eligibility instanceof JsonResponse) {
                    return $eligibility;
                }

                [$amountMinor, $description, $referenceNumber] = $this->balanceCheckoutDetails($record);
            }

            $existing = $checkoutService->activePendingCheckout($record);

            if ($existing) {
                $matchesRequest = $existing->payment_type === $paymentType
                    && (int) $existing->amount === $amountMinor
                    && filled($existing->checkout_session_id)
                    && filled($existing->checkout_url);

                if ($matchesRequest && $checkoutService->provider() !== 'xendit') {
                    return response()->json([
                        'checkout_url' => $existing->checkout_url,
                        'checkout_session_id' => $existing->checkout_session_id,
                        'checkout_expires_at' => $existing->checkout_expires_at?->toIso8601String(),
                        'payment_type' => $existing->payment_type,
                    ]);
                }

                if ($checkoutService->provider() === 'xendit') {
                    // Always issue a new Xendit session so hosted checkout shows the current peso amount.
                    $checkoutService->expireActivePendingCheckouts($record);
                } else {
                    return response()->json([
                        'message' => 'A payment session is being prepared. Please try again.',
                    ], 409);
                }
            }

            if (in_array($paymentType, ['downpayment', 'full_rental'], true)) {
                $hasPaidPayment = RentalPayment::query()
                    ->where('boat_booking_id', $record->id)
                    ->where('provider', 'xendit')
                    ->where('payment_type', $paymentType)
                    ->where('status', 'paid')
                    ->exists();

                if ($hasPaidPayment) {
                    return response()->json([
                        'message' => 'A Xendit payment has already been recorded for this rental.',
                    ], 422);
                }
            }

            $commissionMinor = \App\Support\XenditCheckoutAmounts::commissionForPayment($record, $paymentType, $amountMinor);

            $operatorMinor = $amountMinor - $commissionMinor;

            try {
                $customer = XenditBookingPresenter::customerPayload($user, $record);
            } catch (InvalidArgumentException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $apiAmountPhp = XenditMoney::toApiAmountFromMinor($amountMinor);

            if (app()->environment('local')) {
                Log::info('xendit.checkout.prepare', [
                    'boat_booking_id' => $record->id,
                    'payment_type' => $paymentType,
                    'reference_id' => $referenceNumber,
                    'amount_minor' => $amountMinor,
                    'amount_api_php' => $apiAmountPhp,
                ]);
            }

            try {
                $successUrl = $successBase.(str_contains($successBase, '?') ? '&' : '?').http_build_query(array_filter([
                    'booking_id' => $record->id,
                    'booking_code' => $record->code,
                    'payment_type' => $paymentType,
                    'amount_label' => OrganizationRoute::formatPeso($amountMinor / 100),
                    'app_return' => $appReturn,
                ]));

                $session = $xendit->createGcashPaymentRequest(
                    $referenceNumber,
                    $amountMinor,
                    $description,
                    $successUrl,
                    $cancelUrl,
                );
            } catch (XenditGatewayException $exception) {
                $status = $exception->httpStatus();

                return response()->json([
                    'message' => $exception->passengerMessage(),
                ], $status === 409 ? 409 : 502);
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'GCash checkout could not be started. Try again in a moment.',
                ], 502);
            }

            $sessionId = data_get($session, 'payment_request_id')
                ?? data_get($session, 'payment_session_id');
            $checkoutUrl = data_get($session, 'checkout_url');

            if (
                ! is_string($sessionId) ||
                $sessionId === '' ||
                ! is_string($checkoutUrl) ||
                ! filter_var($checkoutUrl, FILTER_VALIDATE_URL)
            ) {
                return response()->json([
                    'message' => 'Xendit returned an invalid payment session.',
                ], 502);
            }

            RentalPayment::create([
                'organization_id' => $record->organization_id,
                'boat_booking_id' => $record->id,
                'provider' => 'xendit',
                'payment_type' => $paymentType,
                'amount' => $amountMinor,
                'commission_amount' => $commissionMinor,
                'operator_amount' => $operatorMinor,
                'provider_fee' => 0,
                'currency' => 'PHP',
                'status' => 'pending',
                'checkout_session_id' => $sessionId,
                'provider_payment_id' => null,
                'checkout_url' => $checkoutUrl,
                'checkout_expires_at' => $checkoutService->checkoutExpiresAt(),
                'paid_at' => null,
            ]);

            return response()->json([
                'checkout_url' => $checkoutUrl,
                'checkout_session_id' => $sessionId,
                'checkout_expires_at' => $checkoutService->checkoutExpiresAt()->toIso8601String(),
                'payment_type' => $paymentType,
            ]);
        }, 3);
    }

    private function assertDownpaymentEligible(BoatBooking $record): ?JsonResponse
    {
        if ($record->status !== BoatBookingStatus::AwaitingPayment) {
            return response()->json([
                'message' => 'This rental is not awaiting payment.',
            ], 422);
        }

        if ($record->paymentStatus() === BookingPaymentStatus::ForVerification) {
            return response()->json([
                'message' => 'Manual GCash proof is already waiting for verification.',
            ], 422);
        }

        if ($record->paymentStatus() === BookingPaymentStatus::Verified) {
            return response()->json([
                'message' => 'This rental down payment has already been verified.',
            ], 422);
        }

        if (! $record->canSubmitPaymentProof()) {
            return response()->json([
                'message' => 'This rental already has a payment or is not eligible for payment.',
            ], 422);
        }

        if ($record->balanceIsSettled()) {
            return response()->json([
                'message' => 'This rental has already been fully settled.',
            ], 422);
        }

        return null;
    }

    private function assertBalancePaymentEligible(BoatBooking $record): ?JsonResponse
    {
        if ($record->paymentStatus() !== BookingPaymentStatus::Verified) {
            return response()->json([
                'message' => 'Down payment must be verified before paying the remaining balance online.',
            ], 422);
        }

        if (! $record->canRecordBalancePayment()) {
            return response()->json([
                'message' => 'This rental is not ready for a remaining balance payment.',
            ], 422);
        }

        if ($record->paysOperatorGcash()) {
            return response()->json([
                'message' => 'Online balance payment is not available for this legacy booking.',
            ], 422);
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function downpaymentCheckoutDetails(BoatBooking $record): array
    {
        $rentalMinor = $this->toMinorUnits($record->rental_price);
        $downMinor = $this->toMinorUnits($record->down_payment_amount);

        if ($rentalMinor <= 0 || $downMinor <= 0 || $downMinor > $rentalMinor) {
            abort(422, 'This rental does not have valid payment amounts configured.');
        }

        $description = sprintf('SafeSakay Rental #%s - Downpayment', $record->code);
        $referenceNumber = sprintf('SS-%d-DP-%s', $record->id, (string) Str::ulid());

        return [$downMinor, $description, $referenceNumber];
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function balanceCheckoutDetails(BoatBooking $record): array
    {
        $balanceMinor = $this->toMinorUnits($record->remaining_balance);

        if ($balanceMinor <= 0) {
            abort(422, 'This rental does not have a remaining balance to pay.');
        }

        $description = sprintf('SafeSakay Rental #%s - Remaining Balance', $record->code);
        $referenceNumber = sprintf('SS-%d-BAL-%s', $record->id, (string) Str::ulid());

        return [$balanceMinor, $description, $referenceNumber];
    }

    private function assertFullRentalEligible(BoatBooking $record): ?JsonResponse
    {
        if ($record->status !== BoatBookingStatus::AwaitingPayment) {
            return response()->json([
                'message' => 'This rental is not awaiting payment.',
            ], 422);
        }

        if ($record->paymentStatus() === BookingPaymentStatus::ForVerification) {
            return response()->json([
                'message' => 'Manual GCash proof is already waiting for verification.',
            ], 422);
        }

        if ($record->paymentStatus() === BookingPaymentStatus::Verified) {
            return response()->json([
                'message' => 'This rental has already been paid.',
            ], 422);
        }

        if (! $record->canSubmitPaymentProof()) {
            return response()->json([
                'message' => 'This rental is not eligible for payment.',
            ], 422);
        }

        if ($record->paysOperatorGcash()) {
            return response()->json([
                'message' => 'Online full payment is not available for this legacy booking.',
            ], 422);
        }

        $rentalMinor = $this->toMinorUnits($record->rental_price);

        if ($rentalMinor <= 0) {
            return response()->json([
                'message' => 'This rental does not have a valid total amount.',
            ], 422);
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function fullRentalCheckoutDetails(BoatBooking $record): array
    {
        $rentalMinor = $this->toMinorUnits($record->rental_price);

        if ($rentalMinor <= 0) {
            abort(422, 'This rental does not have a valid total amount.');
        }

        $description = sprintf('SafeSakay Rental #%s - Full Payment', $record->code);
        $referenceNumber = sprintf('SS-%d-FULL-%s', $record->id, (string) Str::ulid());

        return [$rentalMinor, $description, $referenceNumber];
    }

    private function toMinorUnits(mixed $amount): int
    {
        if ($amount === null || ! is_numeric($amount)) {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }

}
