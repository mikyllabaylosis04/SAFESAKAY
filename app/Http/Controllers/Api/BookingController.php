<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatBookingStatus;
use App\Enums\GcashPayee;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowRescheduleStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\RegularTripStatus;
use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\OperatorNoShowReport;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\ExpirePendingRentalBookingsService;
use App\Services\OperatorNoShowService;
use App\Services\PassengerNoShowService;
use App\Services\TripConfirmationService;
use App\Services\RegularTripRotationService;
use App\Services\RentalCommissionService;
use App\Services\HostedRentalCheckoutService;
use App\Services\RentalPaymentService;
use App\Services\RentalCompletionService;
use App\Services\RentalRefundService;
use App\Models\RentalRefundRequest;
use App\Services\WeatherSuspensionService;
use App\Services\XenditCheckoutService;
use App\Services\XenditService;
use App\Support\RentalIncidentPresenter;
use App\Support\XenditBookingPresenter;
use App\Support\RentalMobileUiPresenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingController extends Controller
{
    use ReturnsMobileUser;

    public function index(Request $request, RegularTripRotationService $rotation): JsonResponse
    {
        $user = $request->user();

        app(OperatorNoShowService::class)->syncPendingOperatorRescheduleBookingStatuses();
        app(ExpirePendingRentalBookingsService::class)->syncExpiredRentalBookings();

        $bookings = $this->bookingsFor($user)
            ->with([
                'boat' => fn ($query) => $query
                    ->withoutGlobalScope('organization')
                    ->withTrashed()
                    ->with(['pictures', 'operator.gcashAccounts']),
                'passengers',
                'organization',
                'reschedules',
                'operatorNoShowReports',
                'paymentVerifiedBy',
                'balanceRecordedBy',
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->get()
            ->map(function (BoatBooking $booking) {
                try {
                    return $this->transform($booking);
                } catch (\Throwable $exception) {
                    report($exception);

                    return null;
                }
            })
            ->filter()
            ->values();

        $trips = $this->operatorRegularTripsAsBookings($user, $rotation);

        return response()->json(['data' => $trips->concat($bookings)->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isMobileOperator()) {
            abort(403, 'Operators cannot create passenger bookings.');
        }

        $boat = Boat::query()
            ->with(['rentalDestinations', 'operator.gcashAccounts', 'organization'])
            ->find($request->input('boat_id'));

        if ($boat) {
            Organization::abortInactive($boat->organization);
        }

        $capacity = $boat?->capacity ?? 200;
        $assignedDestinations = $boat?->assignedRentalDestinationLabels() ?? [];

        $data = $request->validate([
            'boat_id' => [
                'required',
                'integer',
                Rule::exists('boats', 'id')->whereNull('deleted_at'),
            ],
            'destination' => [
                'required',
                'string',
                'max:120',
                ...($assignedDestinations !== [] ? [Rule::in($assignedDestinations)] : []),
            ],
            'pickup_point' => ['required', 'string', 'max:180'],
            'purpose' => ['nullable', 'string', 'max:180'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'passenger_count' => ['required', 'integer', 'min:1', 'max:'.$capacity],
            'remarks' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['required', 'string', 'max:30'],
        ]);

        if (! $boat || ($conflict = $boat->slotConflictMessage($data['booking_date'], $data['start_time']))) {
            throw ValidationException::withMessages([
                'start_time' => [$conflict ?? 'This boat is not available for private booking.'],
            ]);
        }

        $weatherOrgId = (int) ($boat->organization_id ?? $user->organization_id);
        $weatherBlock = app(WeatherSuspensionService::class)->rentalBlockMessage(
            $weatherOrgId,
            $data['booking_date'],
            $data['destination'],
            $data['start_time'],
        ) ?? app(WeatherSuspensionService::class)->rentalWatchMessage($weatherOrgId, $data['booking_date']);

        if ($weatherBlock) {
            throw ValidationException::withMessages([
                'booking_date' => [$weatherBlock],
            ]);
        }

        $organizationId = $boat->organization_id ?? $user->organization_id;
        $user->attachToOrganization($organizationId);

        $bookingContact = trim((string) $data['contact_number']);
        $pricing = $this->snapshotRentalPricing($boat, $data['destination']);
        $commission = app(RentalCommissionService::class)->snapshotForBooking(
            $organizationId,
            $pricing['rental_price'] !== null ? (float) $pricing['rental_price'] : null,
            isset($pricing['down_payment_amount']) ? (float) $pricing['down_payment_amount'] : null,
        );

        $booking = BoatBooking::createWithUniqueCode([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'boat_id' => $boat->id,
            'customer_name' => $user->name,
            'contact_number' => $bookingContact !== '' ? $bookingContact : null,
            'package' => $data['destination'],
            'destination' => $data['destination'],
            'pickup_point' => $data['pickup_point'],
            'purpose' => $data['purpose'] ?? null,
            'booking_date' => $data['booking_date'],
            'start_time' => $data['start_time'],
            'passenger_count' => $data['passenger_count'],
            'status' => BoatBookingStatus::Pending,
            'remarks' => $data['remarks'] ?? null,
            ...$pricing,
            ...$commission,
            'gcash_payee' => GcashPayee::Barangay,
        ]);

        $booking->load([
            'boat' => fn ($query) => $query->withoutGlobalScope('organization')->with(['pictures', 'operator']),
            'passengers',
            'organization',
        ]);

        return response()->json(['data' => $this->transform($booking)], 201);
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $booking = $this->visibleBooking($request->user(), $booking);

        $report = $booking->operatorNoShowReports()->latest('id')->first();
        if ($report) {
            app(OperatorNoShowService::class)->syncBookingStatusForOperatorReschedule($report);
            $booking->refresh();
        }

        app(XenditCheckoutService::class)->syncPendingFromXendit(
            $booking,
            app(XenditService::class),
            app(RentalPaymentService::class),
        );

        $booking->load([
            'boat' => fn ($query) => $query->withoutGlobalScope('organization')->with(['pictures', 'operator']),
            'passengers',
            'organization',
            'reschedules',
        ]);

        return response()->json(['data' => $this->transform($booking)]);
    }

    public function approve(Request $request, int $booking): JsonResponse
    {
        return $this->updateStatus($request, $booking, BoatBookingStatus::AwaitingPayment);
    }

    public function submitPaymentProof(Request $request, int $booking): JsonResponse
    {
        $user = $request->user();

        if ($user->isMobileOperator()) {
            abort(403);
        }

        $data = $request->validate([
            'gcash_reference' => ['required', 'string', 'min:6', 'max:80'],
            'payment_amount' => ['nullable', 'numeric', 'min:0.01'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $updated = DB::transaction(function () use ($user, $booking, $data, $request) {
            $record = BoatBooking::withoutGlobalScope('organization')
                ->lockForUpdate()
                ->findOrFail($booking);

            $this->assertPassengerCanPay($user, $record);
            $this->assertBookingOrganizationOperational($record);

            $checkoutService = app(HostedRentalCheckoutService::class);
            $checkoutService->expireAllStaleCheckouts($record);

            if ($checkoutService->hasAnyBlockingPendingCheckout($record)) {
                throw ValidationException::withMessages([
                    'receipt' => [
                        'An online GCash checkout is still open for this rental. Complete or wait for it to expire before uploading manual GCash proof.',
                    ],
                ]);
            }

            if (! $record->canSubmitPaymentProof()) {
                throw ValidationException::withMessages([
                    'receipt' => [
                        $record->paymentStatus() === BookingPaymentStatus::ForVerification
                            ? 'Payment proof has already been submitted for this rental.'
                            : 'This rental is not awaiting payment.',
                    ],
                ]);
            }

            $expectedDown = (float) $record->down_payment_amount;

            if ($expectedDown <= 0) {
                throw ValidationException::withMessages([
                    'payment' => ['This rental does not have a down payment amount configured.'],
                ]);
            }

            $submittedAmount = array_key_exists('payment_amount', $data) && $data['payment_amount'] !== null
                ? round((float) $data['payment_amount'], 2)
                : round($expectedDown, 2);

            if (abs($submittedAmount - $expectedDown) > 0.01) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['The amount must match the required down payment.'],
                ]);
            }

            if (BoatBooking::gcashReferenceAlreadyUsed(
                (int) $record->organization_id,
                $data['gcash_reference'],
                (int) $record->id,
            )) {
                throw ValidationException::withMessages([
                    'gcash_reference' => [
                        'This GCash reference number is already linked to another rental payment.',
                    ],
                ]);
            }

            $record->snapshotPaymentRecipient();

            if (filled($record->payment_receipt_path)) {
                Storage::disk('local')->delete($record->payment_receipt_path);
            }

            $path = $request->file('receipt')->store(
                'rental-receipts/'.$record->organization_id.'/'.$record->id,
                'local',
            );

            $record->update([
                'gcash_payee' => $record->gcash_payee,
                'gcash_recipient_account_name' => $record->gcash_recipient_account_name,
                'gcash_recipient_mobile' => $record->gcash_recipient_mobile,
                'gcash_reference' => trim($data['gcash_reference']),
                'payment_amount_submitted' => $submittedAmount,
                'payment_receipt_path' => $path,
                'payment_submitted_at' => now(),
                'payment_status' => BookingPaymentStatus::ForVerification,
                'payment_rejection_reason' => null,
                'payment_verified_at' => null,
                'payment_verified_by' => null,
            ]);

            $checkoutService->expireActivePendingCheckouts($record);

            return $record;
        });

        $updated->load([
            'boat' => fn ($query) => $query->withoutGlobalScope('organization')->with(['pictures', 'operator']),
            'passengers',
            'organization',
        ]);

        return response()->json(['data' => $this->transform($updated)]);
    }

    public function receipt(Request $request, int $booking): StreamedResponse
    {
        $record = $this->visibleBooking($request->user(), $booking);
        $path = $record->payment_receipt_path;

        if (! filled($path)) {
            abort(404);
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path);
            }
        }

        abort(404);
    }

    public function cancel(Request $request, int $booking): JsonResponse
    {
        $user = $request->user();

        if ($user->isMobileOperator()) {
            $response = $this->updateStatus($request, $booking, BoatBookingStatus::Cancelled);

            if ($response->getStatusCode() !== 200) {
                return $response;
            }

            $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);
            app(RentalRefundService::class)->openForCancellation($record, $user, RentalRefundRequest::TYPE_OPERATOR_CANCELLED);

            return $this->respondWithBooking($record);
        }

        $record = $this->bookingsFor($user)->findOrFail($booking);
        $refunds = app(RentalRefundService::class);

        if (! $refunds->passengerCanCancel($record)) {
            return response()->json([
                'message' => $record->canBeCancelled()
                    ? 'Cancellation is only available before the trip date.'
                    : 'This rental can no longer be cancelled.',
            ], 422);
        }

        $data = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record->update([
            'status' => BoatBookingStatus::Cancelled,
            'cancellation_reason' => filled($data['cancellation_reason'] ?? null)
                ? trim((string) $data['cancellation_reason'])
                : 'Cancelled by passenger.',
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
        ]);

        $refunds->handlePassengerCancellation($record->fresh(), $user);

        return $this->respondWithBooking($record->fresh());
    }

    /**
     * POST /api/bookings/{booking}/refund-choice — after the operator/admin cancels a paid rental,
     * the passenger takes a full refund or picks a new date.
     */
    public function refundChoice(Request $request, int $booking, RentalRefundService $refunds): JsonResponse
    {
        $user = $request->user();

        if ($user->isMobileOperator()) {
            abort(403, 'Only passengers can choose a refund or a new date.');
        }

        $record = $this->bookingsFor($user)->findOrFail($booking);
        $data = $request->validate([
            'choice' => ['required', 'in:refund,reschedule'],
            'booking_date' => ['required_if:choice,reschedule', 'nullable', 'date', 'after:today'],
        ]);

        $data['choice'] === 'refund'
            ? $refunds->chooseRefund($record, $user)
            : $refunds->chooseReschedule($record, $user, (string) $data['booking_date']);

        return $this->respondWithBooking($record->fresh());
    }

    public function reschedule(Request $request, int $booking, WeatherSuspensionService $weather): JsonResponse
    {
        $user = $request->user();

        if ($user->isMobileOperator()) {
            abort(403, 'Passengers submit reschedule requests.');
        }

        $record = $this->bookingsFor($user)->findOrFail($booking);
        $this->assertBookingOrganizationOperational($record);
        $data = $request->validate([
            'booking_date' => ['required', 'date', 'after:today'],
        ]);

        $updated = $weather->requestReschedule($record, $user, $data['booking_date']);

        return $this->respondWithBooking($updated);
    }

    public function reviewReschedule(Request $request, int $booking, WeatherSuspensionService $weather): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'min:5', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = $this->visibleBooking($user, $booking);
        $this->assertBookingOrganizationOperational($record);

        $noShow = app(OperatorNoShowService::class);
        $weather = app(WeatherSuspensionService::class);

        if ($noShow->canOperatorRespondReschedule($record, $user)) {
            $noShow->operatorRespondReschedule(
                $record,
                $user,
                $data['action'] === 'approve',
                $data['note'] ?? $data['rejection_reason'] ?? null,
            );

            return $this->respondWithBooking($record->fresh());
        }

        if ($weather->userCanReviewReschedule($record, $user)) {
            $updated = $data['action'] === 'approve'
                ? $weather->approveReschedule($record, $user)
                : $weather->rejectReschedule($record, $user, (string) ($data['rejection_reason'] ?? ''));

            return $this->respondWithBooking($updated);
        }

        if ($user->isMobileOperator() || (int) $record->user_id === (int) $user->id) {
            abort(403, 'Only barangay admin or the assigned boat operator can approve or decline this reschedule.');
        }

        throw ValidationException::withMessages([
            'action' => ['There is no pending reschedule request for this rental.'],
        ]);
    }

    public function reportOperatorNoShow(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $user = $request->user();
        $record = $this->visibleBooking($user, $booking);
        $this->assertBookingOrganizationOperational($record);
        $this->assertPassengerCanPay($user, $record);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'image', 'max:5120'],
            'reason' => ['nullable', 'string', Rule::in(array_keys(\App\Models\OperatorNoShowReport::REASONS))],
        ]);

        $evidencePath = $request->hasFile('evidence')
            ? $request->file('evidence')->store('no-show-evidence', 'public')
            : null;

        $noShow->report($record, $user, $data['note'] ?? null, $evidencePath, $data['reason'] ?? null);

        return $this->respondWithBooking($record->fresh());
    }

    public function chooseOperatorNoShowResolution(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $user = $request->user();
        $record = $this->visibleBooking($user, $booking);
        $this->assertBookingOrganizationOperational($record);
        $this->assertPassengerCanPay($user, $record);

        $data = $request->validate([
            'choice' => ['required', Rule::enum(OperatorNoShowPassengerChoice::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'preferred_booking_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_start_time' => ['nullable', 'string', 'max:8'],
        ]);

        $noShow->passengerChooseResolution(
            $record,
            $user,
            OperatorNoShowPassengerChoice::from($data['choice']),
            $data['note'] ?? null,
            $data['preferred_booking_date'] ?? null,
            $data['preferred_start_time'] ?? null,
        );

        return $this->respondWithBooking($record->fresh());
    }

    public function markPassengerNoShow(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $user = $request->user();
        $record = $this->visibleBooking($user, $booking);
        $this->assertBookingOrganizationOperational($record);
        $this->assertAssignedOperator($user, $record);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'declaration_confirmed' => ['accepted'],
            'evidence' => ['nullable', 'image', 'max:5120'],
        ], [
            'declaration_confirmed.accepted' => 'Confirm that the boat and operator were ready at the pickup location and the waiting period passed.',
        ]);

        $evidencePath = $request->hasFile('evidence')
            ? $request->file('evidence')->store('no-show-evidence', 'public')
            : null;

        $updated = $noShow->markPassengerNoShow(
            $record,
            $user,
            $data['note'] ?? null,
            true,
            $evidencePath,
        );

        return $this->respondWithBooking($updated);
    }

    public function disputePassengerNoShow(Request $request, int $booking, PassengerNoShowService $passengerNoShows): JsonResponse
    {
        $user = $request->user();
        $record = $this->visibleBooking($user, $booking);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $passengerNoShows->dispute($record, $user, $data['reason']);

        return $this->respondWithBooking($record->fresh());
    }

    public function confirmOperatorAvailability(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $user = $request->user();
        $record = $this->visibleBooking($user, $booking);
        $this->assertBookingOrganizationOperational($record);
        $this->assertAssignedOperator($user, $record);

        $updated = $noShow->confirmAvailability($record, $user);

        return $this->respondWithBooking($updated);
    }

    public function complete(Request $request, int $booking): JsonResponse
    {
        return $this->updateStatus($request, $booking, BoatBookingStatus::Completed);
    }

    public function verifyPayment(Request $request, int $booking, RentalPaymentService $payments): JsonResponse
    {
        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);
        $this->assertBookingOrganizationOperational($record);
        $payments->authorizeVerifier($request->user(), $record);

        $input = $request->validate([
            'confirmed_gcash_transaction' => ['required', 'accepted'],
        ]);

        $updated = $payments->verify($record, $request->user(), $input);

        return $this->respondWithBooking($updated);
    }

    public function rejectPayment(Request $request, int $booking, RentalPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);
        $this->assertBookingOrganizationOperational($record);
        $updated = $payments->reject($record, $request->user(), $data['reason']);

        return $this->respondWithBooking($updated);
    }

    public function recordBalance(Request $request, int $booking, RentalPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'gcash'])],
            'reference' => ['nullable', 'string', 'min:6', 'max:80'],
            'confirmed' => ['accepted'],
        ]);

        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);
        $this->assertBookingOrganizationOperational($record);
        $updated = $payments->recordBalance(
            $record,
            $request->user(),
            $data['method'],
            $data['reference'] ?? null,
        );

        return $this->respondWithBooking($updated);
    }

    public function update(Request $request, int $booking): JsonResponse
    {
        $status = $request->validate([
            'status' => ['required', 'in:approved,cancelled,completed'],
        ])['status'];

        return match ($status) {
            'approved' => $this->approve($request, $booking),
            'completed' => $this->complete($request, $booking),
            default => $this->cancel($request, $booking),
        };
    }

    private function updateStatus(Request $request, int $bookingId, BoatBookingStatus $status): JsonResponse
    {
        $user = $request->user();

        if (! $user->isMobileOperator()) {
            abort(403);
        }

        $booking = $this->visibleBooking($user, $bookingId);
        $this->assertBookingOrganizationOperational($booking);

        if ($status === BoatBookingStatus::Completed) {
            $completion = app(RentalCompletionService::class);

            if ($reason = $completion->blockedReason($booking)) {
                return response()->json(['message' => $reason], 422);
            }

            return $this->respondWithBooking($completion->complete($booking, $user));
        }

        if (in_array($status, [BoatBookingStatus::Confirmed, BoatBookingStatus::AwaitingPayment], true)) {
            $booking->loadMissing('boat');
            $date = $booking->booking_date->toDateString();
            $time = Carbon::parse($booking->start_time)->format('H:i');
            $conflict = $booking->boat?->slotConflictMessage($date, $time, $booking->id);

            if ($conflict) {
                return response()->json([
                    'message' => $conflict,
                ], 422);
            }

            $weatherBlock = app(WeatherSuspensionService::class)->rentalBlockMessage(
                (int) ($booking->organization_id ?: $booking->boat?->organization_id),
                $date,
                $booking->destination ?: $booking->package,
                $time,
            );

            if ($weatherBlock) {
                return response()->json([
                    'message' => $weatherBlock,
                ], 422);
            }
        }

        if ($status === BoatBookingStatus::AwaitingPayment && $booking->isExclusiveRental()) {
            $booking->loadMissing('boat.operator');

            if ($booking->boat?->operator?->isExclusiveRentalRestricted()) {
                return response()->json([
                    'message' => 'Your barangay admin restricted new exclusive rental assignments because of overdue commission settlements. Contact the barangay office.',
                ], 422);
            }
        }

        if ($status === BoatBookingStatus::Ongoing && $booking->status->isWeatherPaused()) {
            return response()->json([
                'message' => 'This rental is on weather hold and cannot depart until it is rescheduled.',
            ], 422);
        }

        $payload = ['status' => $status];
        if ($status === BoatBookingStatus::AwaitingPayment) {
            $booking->snapshotPaymentRecipient();
            $payload['gcash_payee'] = $booking->gcashPayee();
            $payload['gcash_recipient_account_name'] = $booking->gcash_recipient_account_name;
            $payload['gcash_recipient_mobile'] = $booking->gcash_recipient_mobile;
        }
        if ($status === BoatBookingStatus::Completed) {
            $payload['completed_at'] = now();
        }

        $booking->update($payload);

        return $this->respondWithBooking($booking);
    }

    private function respondWithBooking(BoatBooking $booking): JsonResponse
    {
        $booking->load([
            'boat' => fn ($query) => $query->withoutGlobalScope('organization')->with(['pictures', 'operator.gcashAccounts']),
            'passengers',
            'organization',
            'reschedules',
            'paymentVerifiedBy',
            'balanceRecordedBy',
            'rentalPayments',
            'commission',
        ]);

        return response()->json(['data' => $this->transform($booking)]);
    }

    private function assertAssignedOperator(User $user, BoatBooking|int $booking): void
    {
        if (! $user->isMobileOperator()) {
            abort(403);
        }

        $record = $booking instanceof BoatBooking
            ? $booking
            : BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);

        if (! Operator::userManagesBoat($user, $record->boat_id)) {
            abort(403);
        }
    }

    /**
     * @return Builder<BoatBooking>
     */
    private function bookingsFor(User $user)
    {
        $query = BoatBooking::withoutGlobalScope('organization');

        if ($user->isMobileOperator()) {
            $boatIds = Operator::managedBoatIdsForUser($user);

            $pendingRescheduleBookingIds = app(OperatorNoShowService::class)
                ->pendingRescheduleBookingIdsForOperator($user);

            return $query->where(function (Builder $inner) use ($boatIds, $pendingRescheduleBookingIds) {
                $inner->whereIn('boat_id', $boatIds);

                if ($pendingRescheduleBookingIds !== []) {
                    $inner->orWhereIn('id', $pendingRescheduleBookingIds);
                }
            });
        }

        return $query->where('user_id', $user->id);
    }

    private function completeBlockedReason(BoatBooking $booking): ?string
    {
        if (! in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)
            || $booking->canCompleteTrip()) {
            return null;
        }

        return app(RentalCompletionService::class)->blockedReason($booking);
    }

    private function assertPassengerCanPay(User $user, BoatBooking $booking): void
    {
        if ((int) $booking->user_id !== (int) $user->id) {
            abort(403);
        }
    }

    private function assertBookingOrganizationOperational(BoatBooking $booking): void
    {
        $booking->loadMissing('organization');
        Organization::abortInactive($booking->organization);
    }

    private function visibleBooking(User $user, int $bookingId): BoatBooking
    {
        $booking = BoatBooking::withoutGlobalScope('organization')->findOrFail($bookingId);

        if ((int) $booking->user_id === (int) $user->id) {
            return $booking;
        }

        if ($user->isMobileOperator()) {
            if (Operator::userManagesBoat($user, $booking->boat_id)) {
                return $booking;
            }

            if (app(OperatorNoShowService::class)->canOperatorRespondReschedule($booking, $user)) {
                return $booking;
            }

            if (app(OperatorNoShowService::class)->operatorHasNoShowRescheduleVisibility($booking, $user)) {
                return $booking;
            }
        }

        abort(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(BoatBooking $booking): array
    {
        $booking->loadMissing([
            'passengers',
            'organization',
            'reschedules',
            'paymentVerifiedBy',
            'balanceRecordedBy',
            'rentalPayments',
            'commission',
        ]);

        if (! $booking->boat) {
            $booking->setRelation(
                'boat',
                Boat::query()
                    ->withoutGlobalScope('organization')
                    ->withTrashed()
                    ->with(['pictures', 'operator.gcashAccounts'])
                    ->find($booking->boat_id),
            );
        } else {
            $booking->loadMissing(['boat.operator.gcashAccounts']);
        }

        if (! $booking->boat) {
            throw new \RuntimeException('Booking '.$booking->id.' is missing its boat.');
        }
        $time = Carbon::parse($booking->start_time);
        $viewer = request()->user();
        $display = app(OperatorNoShowService::class)->bookingDisplayStatus($booking, $viewer);
        $displayStatus = $display['status'];
        $approved = in_array($displayStatus, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
            BoatBookingStatus::Completed,
        ], true);

        $mobileStatus = $display['mobile'];
        $cancellationDisplay = RentalIncidentPresenter::cancellationDisplay($booking);
        $weatherHold = $booking->status === BoatBookingStatus::WeatherHold;

        $operator = null;
        $includeOperator = $approved
            || ($viewer instanceof User && $viewer->isMobileOperator());
        if ($includeOperator && $booking->boat?->operator) {
            $operator = [
                'full_name' => $booking->boat->operator->full_name,
                'contact_number' => $booking->boat->operator->contact_number,
                'address' => $booking->boat->operator->address,
                'picture_url' => $this->operatorPictureUrl($booking->boat),
            ];
        }

        $payments = app(RentalPaymentService::class);
        $mayVerifyPayment = $viewer instanceof User
            && $payments->userMayVerify($viewer, $booking);
        $mayRecordBalance = $viewer instanceof User
            && $payments->userMayRecordBalance($viewer, $booking);

        $noShowReport = $booking->operatorNoShowReports->sortByDesc('id')->first()
            ?? $booking->operatorNoShowReports()->latest('id')->first();
        $requestedBookingDate = $booking->requested_booking_date ?? $booking->pendingRequestedBookingDate();
        $noShowRescheduleBoatName = null;
        $noShowRescheduleBoatCode = null;
        $noShowRescheduleDateLabel = null;
        $noShowRescheduleTimeLabel = null;

        if ($noShowReport instanceof OperatorNoShowReport
            && $noShowReport->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $noShowReport->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator) {
            $noShowReport->loadMissing('rescheduleBoat');
            if ($noShowReport->preferred_booking_date) {
                $requestedBookingDate = $noShowReport->preferred_booking_date;
                $noShowRescheduleDateLabel = $noShowReport->preferred_booking_date->format('M d, Y');
            }
            if ($noShowReport->preferred_start_time) {
                $noShowRescheduleTimeLabel = Carbon::parse($noShowReport->preferred_start_time)->format('g:i A');
            }
            $noShowRescheduleBoatName = $noShowReport->rescheduleBoat?->name;
            $noShowRescheduleBoatCode = $noShowReport->rescheduleBoat?->code;
        }

        return [
            'id' => $booking->id,
            'kind' => 'rental',
            'code' => $booking->code,
            'status' => $mobileStatus,
            'status_raw' => $displayStatus->value,
            'status_label' => $display['label'],
            'status_stored' => $booking->status->value,
            'operator_no_show_reschedule_phase' => $display['phase'],
            'customer_name' => $booking->customer_name,
            'contact_number' => $booking->contact_number,
            'destination' => $booking->destination ?: $booking->package,
            'pickup_point' => $booking->pickup_point,
            'purpose' => $booking->purpose,
            'rental_price' => $booking->rental_price !== null ? (float) $booking->rental_price : null,
            'rental_price_label' => $booking->rental_price !== null
                ? OrganizationRoute::formatPeso($booking->rental_price)
                : null,
            'down_payment_percent' => $booking->down_payment_percent !== null
                ? (float) $booking->down_payment_percent
                : null,
            'down_payment_percent_label' => $booking->down_payment_percent !== null
                ? OrganizationRoute::formatPercent((float) $booking->down_payment_percent)
                : null,
            'down_payment_amount' => $booking->down_payment_amount !== null
                ? (float) $booking->down_payment_amount
                : null,
            'down_payment_amount_label' => $booking->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null,
            'remaining_balance' => $booking->remaining_balance !== null
                ? (float) $booking->remaining_balance
                : null,
            'remaining_balance_label' => $booking->remaining_balance !== null
                ? OrganizationRoute::formatPeso($booking->remaining_balance)
                : null,
            'payment_required' => $booking->requiresDownPayment(),
            'can_pay' => $booking->canSubmitPaymentProof(),
            'xendit' => XenditBookingPresenter::mobilePayload($booking),
            'online_payment' => XenditBookingPresenter::mobilePayload($booking),
            'can_verify_payment' => $booking->canVerifyPayment() && $mayVerifyPayment,
            'verify_payment_blocked_reason' => $viewer instanceof User
                ? $payments->verifyPaymentBlockedReason($viewer, $booking)
                : null,
            'can_record_balance' => false,
            'gcash_payee' => $booking->gcashPayee()->value,
            'gcash_payee_label' => $booking->paysOperatorGcash()
                ? $booking->gcashPayee()->label()
                : 'SafeSakay',
            'payment_verified_by_role' => $booking->paysBarangayGcash() ? 'admin' : 'operator',
            'can_complete' => $booking->canCompleteTrip(),
            'complete_settles_balance' => $booking->canCompleteTrip() && $booking->canRecordBalancePayment(),
            'complete_blocked_reason' => $this->completeBlockedReason($booking),
            'payment_status' => $booking->paymentStatus()->value,
            'payment_status_label' => $booking->paymentStatus()->label(),
            'payment_submitted' => $booking->hasPaymentSubmission(),
            'payment_rejection_reason' => $booking->payment_rejection_reason,
            'payment_submitted_at' => $booking->payment_submitted_at?->toIso8601String(),
            'payment_submitted_at_label' => $this->manilaStamp($booking->payment_submitted_at),
            'payment_verified_at_label' => $this->manilaStamp($booking->payment_verified_at),
            'payment_verified_by_name' => $booking->paymentVerifiedBy?->name,
            'payment_receipt_url' => filled($booking->payment_receipt_path)
                ? '/api/bookings/'.$booking->id.'/receipt'
                : null,
            'gcash_reference' => $booking->gcash_reference,
            'payment_amount_submitted' => $booking->payment_amount_submitted !== null
                ? (float) $booking->payment_amount_submitted
                : null,
            'gcash_recipient_account_name' => $booking->gcash_recipient_account_name,
            'gcash_recipient_mobile' => $booking->gcash_recipient_mobile,
            'balance_status' => $booking->balanceStatus()->value,
            'balance_status_label' => $booking->balanceStatus()->label(),
            'balance_method' => $booking->balance_method,
            'balance_method_label' => match ($booking->balance_method) {
                'cash' => 'Cash',
                'gcash' => 'GCash',
                default => null,
            },
            'balance_reference' => $booking->balance_reference,
            'balance_paid_at_label' => $this->manilaStamp($booking->balance_paid_at),
            'completed_at' => $booking->completed_at?->toIso8601String(),
            'completed_at_label' => $this->manilaStamp($booking->completed_at),
            'completed_at_date_label' => $this->manilaDate($booking->completed_at),
            'completed_at_time_label' => $this->manilaTime($booking->completed_at),
            'created_at' => $booking->created_at?->toIso8601String(),
            'created_at_label' => $this->manilaDate($booking->created_at),
            'created_at_time_label' => $this->manilaTime($booking->created_at),
            'down_payment_method_label' => $this->downPaymentMethodLabel($booking),
            'down_payment_recorded_at_label' => $this->downPaymentRecordedAtLabel($booking),
            'down_payment_recorded_at_kind' => $this->downPaymentRecordedAtKind($booking),
            'paid_as_full_rental' => $this->paidAsFullRental($booking),
            'initial_payment_title' => $this->paidAsFullRental($booking) ? 'Full Payment' : 'Down Payment',
            'initial_payment_amount' => $this->initialPaymentAmount($booking),
            'initial_payment_amount_label' => OrganizationRoute::formatPeso($this->initialPaymentAmount($booking)),
            'balance_paid_amount' => $this->balancePaidAmount($booking),
            'balance_paid_amount_label' => OrganizationRoute::formatPeso($this->balancePaidAmount($booking)),
            'total_paid' => $this->totalPaidAmount($booking),
            'total_paid_label' => OrganizationRoute::formatPeso($this->totalPaidAmount($booking)),
            'overall_payment_status' => $booking->overallPaymentStatus(),
            'overall_payment_status_label' => $booking->overallPaymentStatusLabel(),
            'fully_paid_at_label' => $booking->isFullyPaid()
                ? ($this->manilaStamp($booking->balance_paid_at)
                    ?? $this->manilaStamp($booking->payment_verified_at))
                : null,
            'commission' => $this->commissionPayload($booking),
            'gcash_account_name' => $booking->gcash_recipient_account_name
                ?: $this->downPaymentGcashAccountName($booking),
            'gcash_qr_url' => $this->downPaymentGcashQrUrl($booking),
            'gcash_mobile_number' => $booking->gcash_recipient_mobile,
            'balance_gcash_account_name' => $this->balanceGcashAccountName($booking),
            'balance_gcash_qr_url' => $this->balanceGcashQrUrl($booking),
            'balance_gcash_mobile_number' => $this->balanceGcashMobileNumber($booking),
            'booking_date' => $booking->booking_date->toDateString(),
            'booking_date_label' => $booking->booking_date->format('M d, Y'),
            'original_booking_date' => ($booking->original_booking_date ?? $booking->booking_date)->toDateString(),
            'original_booking_date_label' => ($booking->original_booking_date ?? $booking->booking_date)->format('M d, Y'),
            'weather_hold' => $weatherHold,
            'weather_reason' => $weatherHold ? $booking->weather_reason : null,
            'weather_reason_label' => $weatherHold && $booking->weather_reason
                ? (\App\Enums\WeatherHoldReason::tryFrom($booking->weather_reason)?->label() ?? 'Bad Weather')
                : null,
            'reschedule_status' => $booking->reschedule_status,
            'requested_booking_date' => $requestedBookingDate?->toDateString(),
            'requested_booking_date_label' => $requestedBookingDate?->format('M d, Y'),
            'no_show_reschedule_boat_name' => $noShowRescheduleBoatName,
            'no_show_reschedule_boat_code' => $noShowRescheduleBoatCode,
            'no_show_reschedule_date_label' => $noShowRescheduleDateLabel,
            'no_show_reschedule_time_label' => $noShowRescheduleTimeLabel,
            'reschedule_rejection_reason' => $booking->reschedule_rejection_reason,
            'can_reschedule' => $booking->canRequestReschedule(),
            'can_review_reschedule' => $viewer instanceof User && (
                app(WeatherSuspensionService::class)->userCanReviewReschedule($booking, $viewer)
                || app(OperatorNoShowService::class)->canOperatorRespondReschedule($booking, $viewer)
            ),
            'downpayment_carried_forward' => $booking->paymentStatus()->value === 'verified',
            'start_time' => $time->format('H:i'),
            'start_time_label' => $noShowRescheduleTimeLabel ?? $time->format('g:i A'),
            'passenger_count' => (string) $booking->passenger_count,
            'remarks' => $booking->remarks,
            'feedback' => $booking->feedback?->toPayload(),
            'passenger' => [
                'name' => $booking->customer_name,
                'full_name' => $booking->customer_name,
                'contact_number' => $booking->contact_number,
            ],
            'passengers' => $booking->passengers
                ->map(fn ($passenger) => $passenger->toRecord())
                ->values()
                ->all(),
            'operator' => $operator,
            'boat' => [
                'id' => $booking->boat->id,
                'name' => $booking->boat->name,
                'code' => $booking->boat->code,
                'capacity' => $booking->boat->capacity,
                'license_no' => $booking->boat->license_no,
                'operator_name' => $booking->boat->operator?->full_name,
                'picture_url' => $this->boatPictureUrl($booking->boat),
                'life_jacket_quantity' => $booking->boat->life_jacket_quantity,
                'safety_equipment' => $booking->boat->safety_equipment ?? [],
                'safety_information' => $booking->boat->safety_information,
            ],
            ...$this->operatorNoShowPayload($booking, request()->user()),
            'cancellation_display' => $cancellationDisplay,
            'rental_card_message' => $cancellationDisplay['list_message'] ?? $cancellationDisplay['card_message'] ?? null,
            'incident_information' => RentalIncidentPresenter::forBooking($booking, $viewer),
            'incident_summary' => RentalIncidentPresenter::listSummary($booking),
            'rental_ui' => \App\Support\RentalMobileUiPresenter::forBooking($booking, $viewer),
            'checkout_context' => \App\Support\RentalCheckoutContext::forBooking($booking),
            'checkout_policy' => \App\Support\RentalCheckoutPolicy::mobilePayload($booking, $viewer),
            ...app(RentalRefundService::class)->mobilePayload($booking, $viewer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operatorNoShowPayload(BoatBooking $booking, ?User $viewer): array
    {
        if (! $viewer instanceof User) {
            return [
                'can_report_operator_no_show' => false,
                'can_mark_passenger_no_show' => false,
                'can_confirm_operator_availability' => false,
                'can_operator_respond_reschedule' => false,
                'can_choose_operator_no_show_resolution' => false,
                'operator_availability_confirmed' => $booking->operator_availability_confirmed_at !== null,
                'operator_availability_confirmed_at_label' => $booking->operator_availability_confirmed_at?->format('M d, Y · g:i A'),
                'operator_no_show_report_pending' => false,
                'operator_no_show_incident' => null,
                'operator_no_show_resolution' => null,
                'operator_no_show_resolution_label' => null,
            ];
        }

        $service = app(OperatorNoShowService::class);
        $latestReport = $booking->operatorNoShowReports()->latest('id')->first();
        $incident = $service->mobileIncidentPayload($booking, $viewer);

        $tripConfirmation = $viewer->isMobileOperator()
            ? app(TripConfirmationService::class)->operatorTripPayload($booking, $viewer)
            : null;

        $passengerNoShows = app(PassengerNoShowService::class);
        $passengerReport = $booking->passengerNoShowReport;
        $isPassengerViewer = (int) $booking->user_id === (int) $viewer->id;
        $isBoatOperator = $viewer->isMobileOperator()
            && (int) $booking->boat?->operator?->user_id === (int) $viewer->id;

        return [
            'can_report_operator_no_show' => $service->canPassengerReport($booking, $viewer),
            'operator_no_show_grace_minutes' => $service->operatorGraceMinutes($booking),
            'operator_no_show_report_opens_at_label' => $service->reportOpensAt($booking)?->format('g:i A'),
            'passenger_no_show_grace_minutes' => $passengerNoShows->graceMinutes($booking),
            'passenger_no_show_opens_at_label' => $passengerNoShows->opensAt($booking)?->format('g:i A'),
            'passenger_no_show_report' => $passengerReport && ($isPassengerViewer || $isBoatOperator)
                ? [
                    ...$passengerReport->toPayload(),
                    'can_dispute' => $passengerNoShows->canDispute($booking, $viewer),
                ]
                : null,
            'can_mark_passenger_no_show' => $service->canOperatorMarkPassengerNoShow($booking, $viewer),
            'show_passenger_no_show_action' => $service->canOperatorMarkPassengerNoShow($booking, $viewer),
            'passenger_no_show_blocked_reason' => RentalMobileUiPresenter::passengerNoShowBlockedReason($booking, $viewer),
            'can_confirm_operator_availability' => $service->canOperatorConfirmAvailability($booking, $viewer),
            'trip_confirmation' => $tripConfirmation,
            'can_operator_respond_reschedule' => $service->canOperatorRespondReschedule($booking, $viewer),
            'can_choose_operator_no_show_resolution' => $incident
                ? (bool) $incident['can_choose_resolution']
                : false,
            'operator_availability_confirmed' => $booking->operator_availability_confirmed_at !== null,
            'operator_availability_confirmed_at_label' => $booking->operator_availability_confirmed_at?->format('M d, Y · g:i A'),
            'operator_no_show_report_pending' => $latestReport?->status
                === OperatorNoShowReportStatus::Pending,
            'operator_no_show_incident' => $incident,
            'operator_no_show_resolution' => $latestReport?->resolution?->value,
            'operator_no_show_resolution_label' => $latestReport?->resolution?->label(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function operatorRegularTripsAsBookings(User $user, RegularTripRotationService $rotation)
    {
        if (! $user->isMobileOperator()) {
            return collect();
        }

        $user->loadMissing(['organization', 'operatorProfiles.boat']);
        $boat = $user->operatorProfiles->first()?->boat;

        if (! $boat) {
            return collect();
        }

        if ($user->organization_id && $user->organization?->providesOperations()) {
            try {
                $rotation->syncUpcoming($user->organization_id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return RegularTrip::query()
            ->with(['boat.pictures', 'boat.operator'])
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::OnTrip,
                RegularTripStatus::Delayed,
            ])
            ->orderBy('trip_date')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get()
            ->map(fn (RegularTrip $trip) => $this->transformRegularTrip($trip))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRegularTrip(RegularTrip $trip): array
    {
        $trip->loadMissing('boat.pictures');
        $time = Carbon::parse($trip->departure_time);
        $title = $trip->isOutbound() ? 'Regular trip' : 'Return trip';
        $order = (int) ($trip->rotation_order ?: 1);

        return [
            'id' => 1_000_000 + $trip->id,
            'regular_trip_id' => $trip->id,
            'kind' => 'regular_trip',
            'code' => sprintf('RT-%d', $trip->id),
            'status' => 'approved',
            'status_raw' => $trip->status->value,
            'status_label' => $trip->status->label(),
            'customer_name' => $title,
            'contact_number' => null,
            'destination' => $trip->headingLabel(),
            'pickup_point' => $trip->routeLabel(),
            'purpose' => 'regular_trip',
            'booking_date' => $trip->trip_date->toDateString(),
            'booking_date_label' => $trip->trip_date->format('M d, Y'),
            'start_time' => $trip->hasScheduledTime() ? $time->format('H:i') : '',
            'start_time_label' => $trip->scheduledTimeLabel(),
            'rotation_order' => $order,
            'leave_order_label' => RegularTrip::leaveOrderLabel($order),
            'trip_number_label' => $trip->tripNumberLabel(),
            'cycle_number' => $trip->cycleNumber(),
            'passenger_count' => (string) $trip->passenger_count,
            'remarks' => $trip->isOutbound()
                ? 'Assigned regular trip'
                : 'Return trip — mark arrived at origin, then record passengers',
            'has_departure_time' => $trip->hasScheduledTime(),
            'scheduled_time_label' => $trip->scheduledTimeLabel(),
            'actual_departure_label' => $trip->actualDepartureLabel(),
            'actual_arrival_label' => $trip->actualArrivalLabel(),
            'boarding' => $trip->boardingPayload(),
            'is_outbound' => $trip->isOutbound(),
            'role' => $trip->isOutbound() ? 'going' : 'returning',
            'status_label' => $trip->workflowStatusLabel(),
            'can_depart' => $trip->isOutbound() && $trip->status->isWaiting(),
            'can_arrive_destination' => false,
            'can_arrive_origin' => $trip->isOutbound() && $trip->status === RegularTripStatus::OnTrip,
            'can_complete_return' => ! $trip->isOutbound() && $trip->arrived_at !== null && $trip->status !== RegularTripStatus::Completed,
            'passenger' => [
                'name' => $title,
                'full_name' => $title,
                'contact_number' => null,
            ],
            'passengers' => [],
            'operator' => null,
            'boat' => [
                'id' => $trip->boat?->id,
                'name' => $trip->boat?->name,
                'code' => $trip->boat?->code,
                'capacity' => $trip->boat?->capacity,
                'license_no' => $trip->boat?->license_no,
                'picture_url' => $trip->boat ? $this->boatPictureUrl($trip->boat) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionPayload(BoatBooking $booking): array
    {
        $enabled = (bool) $booking->commission_enabled && (float) $booking->commission_amount > 0;

        return [
            'enabled' => $enabled,
            'included_in_total' => true,
            'percentage' => $enabled ? (float) $booking->commission_percentage : 0,
            'percentage_label' => $enabled
                ? OrganizationRoute::formatPercent((float) $booking->commission_percentage)
                : '0%',
            'amount' => $enabled ? (float) $booking->commission_amount : 0,
            'amount_label' => OrganizationRoute::formatPeso($enabled ? $booking->commission_amount : 0),
            'operator_net' => $enabled
                ? (float) $booking->operator_net_amount
                : (float) ($booking->rental_price ?? 0),
            'operator_net_label' => OrganizationRoute::formatPeso(
                $enabled ? $booking->operator_net_amount : ($booking->rental_price ?? 0),
            ),
            'note' => $this->commissionNote($booking, $enabled),
        ];
    }

    private function commissionNote(BoatBooking $booking, bool $enabled): ?string
    {
        if ($booking->paysOperatorGcash()) {
            return $enabled
                ? 'Legacy booking: operator GCash down payment. Barangay commission is included in the total rental price. The operator later remits commission to barangay GCash.'
                : null;
        }

        return $enabled
            ? 'Pay online through SafeSakay (GCash via Xendit). The barangay commission is taken from that payment. Pay any remaining balance to the operator on the trip.'
            : 'Pay online through SafeSakay (GCash via Xendit). Pay any remaining balance to the operator on the trip.';
    }

    private function downPaymentGcashAccountName(BoatBooking $booking): ?string
    {
        $booking->loadMissing(['organization']);

        return $booking->organization?->gcash_account_name;
    }

    private function downPaymentGcashQrUrl(BoatBooking $booking): ?string
    {
        $booking->loadMissing(['organization']);
        $path = $booking->organization?->gcash_qr_path;

        return $this->publicStorageUrl($path);
    }

    private function balanceGcashAccountName(BoatBooking $booking): ?string
    {
        $booking->loadMissing(['boat.operator.gcashAccounts']);

        return $booking->boat?->operator?->approvedGcashAccount()?->account_name;
    }

    private function balanceGcashQrUrl(BoatBooking $booking): ?string
    {
        $booking->loadMissing(['boat.operator.gcashAccounts']);
        $path = $booking->boat?->operator?->approvedGcashAccount()?->qr_path;

        return $this->publicStorageUrl($path);
    }

    private function balanceGcashMobileNumber(BoatBooking $booking): ?string
    {
        $booking->loadMissing(['boat.operator.gcashAccounts']);

        return $booking->boat?->operator?->approvedGcashAccount()?->mobile_number;
    }

    private function publicStorageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $relative = public_storage_url($path);

        if (! $relative) {
            return null;
        }

        $forwardedHost = request()->headers->get('X-Forwarded-Host');
        $forwardedProto = request()->headers->get('X-Forwarded-Proto', request()->getScheme());

        if (is_string($forwardedHost) && $forwardedHost !== '') {
            return $forwardedProto.'://'.$forwardedHost.$relative;
        }

        return request()->getSchemeAndHttpHost().$relative;
    }

    private function gcashAccountName(BoatBooking $booking): ?string
    {
        return $this->downPaymentGcashAccountName($booking);
    }

    private function gcashQrUrl(BoatBooking $booking): ?string
    {
        return $this->downPaymentGcashQrUrl($booking);
    }

    /**
     * @return array{
     *     rental_price: string|null,
     *     down_payment_percent: string|null,
     *     down_payment_amount: string|null,
     *     remaining_balance: string|null
     * }
     */
    private function snapshotRentalPricing(?Boat $boat, string $destination): array
    {
        $empty = [
            'rental_price' => null,
            'down_payment_percent' => null,
            'down_payment_amount' => null,
            'remaining_balance' => null,
        ];

        if (! $boat) {
            return $empty;
        }

        $route = $boat->rentalDestinations
            ->first(function (OrganizationRoute $route) use ($destination, $boat) {
                return $route->isExclusiveBoatRental()
                    && $route->is_active
                    && (int) $route->organization_id === (int) $boat->organization_id
                    && $route->destination === $destination;
            });

        if (! $route) {
            return $empty;
        }

        $breakdown = $route->priceBreakdown();

        if ($breakdown === null) {
            return $empty;
        }

        return [
            'rental_price' => number_format($breakdown['rental_price'], 2, '.', ''),
            'down_payment_percent' => number_format($breakdown['down_payment_percent'], 2, '.', ''),
            'down_payment_amount' => number_format($breakdown['down_payment_amount'], 2, '.', ''),
            'remaining_balance' => number_format($breakdown['remaining_balance'], 2, '.', ''),
        ];
    }

    private function manilaStamp(?Carbon $at): ?string
    {
        return $at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A');
    }

    private function manilaDate(?Carbon $at): ?string
    {
        return $at?->copy()->timezone('Asia/Manila')->format('M d, Y');
    }

    private function manilaTime(?Carbon $at): ?string
    {
        return $at?->copy()->timezone('Asia/Manila')->format('g:i A');
    }

    private function downPaymentMethodLabel(BoatBooking $booking): string
    {
        $remarks = (string) ($booking->remarks ?? '');

        if (str_contains($remarks, '@walk-in:cash')
            || str_contains($remarks, 'Walk-in down payment received in cash')) {
            return 'Cash';
        }

        if (str_contains($remarks, '@walk-in:gcash')
            || str_contains($remarks, 'Walk-in GCash payment submitted')) {
            return 'GCash';
        }

        if (filled($booking->gcash_reference) || filled($booking->payment_receipt_path)) {
            return 'GCash';
        }

        return 'GCash';
    }

    private function downPaymentRecordedAtKind(BoatBooking $booking): ?string
    {
        if ($booking->payment_verified_at) {
            return 'verified';
        }

        if ($booking->payment_submitted_at) {
            return 'submitted';
        }

        $remarks = (string) ($booking->remarks ?? '');
        if (str_contains($remarks, '@walk-in:cash')
            || str_contains($remarks, 'Walk-in down payment received in cash')) {
            return 'recorded';
        }

        return null;
    }

    private function downPaymentRecordedAtLabel(BoatBooking $booking): ?string
    {
        if ($booking->payment_verified_at) {
            return $this->manilaStamp($booking->payment_verified_at);
        }

        if ($booking->payment_submitted_at) {
            return $this->manilaStamp($booking->payment_submitted_at);
        }

        $remarks = (string) ($booking->remarks ?? '');
        if (str_contains($remarks, '@walk-in:cash')
            || str_contains($remarks, 'Walk-in down payment received in cash')) {
            return $this->manilaStamp($booking->created_at);
        }

        return null;
    }

    private function paidAsFullRental(BoatBooking $booking): bool
    {
        $booking->loadMissing('rentalPayments');

        return $booking->rentalPayments->contains(
            fn ($payment) => $payment->payment_type === 'full_rental' && $payment->isPaid(),
        );
    }

    private function ledgerPaidAmount(BoatBooking $booking): float
    {
        $booking->loadMissing('rentalPayments');

        $total = $booking->rentalPayments
            ->filter(fn ($payment) => $payment->isPaid())
            ->sum(function ($payment) {
                $refunded = $payment->isRefunded()
                    ? min((int) $payment->amount, (int) ($payment->refunded_amount ?? $payment->amount))
                    : 0;

                return max(((int) $payment->amount - $refunded) / 100, 0);
            });

        return round((float) $total, 2);
    }

    private function initialPaymentAmount(BoatBooking $booking): float
    {
        if ($booking->paymentStatus() !== BookingPaymentStatus::Verified) {
            return round((float) ($booking->down_payment_amount ?? 0), 2);
        }

        if ($this->paidAsFullRental($booking)) {
            $ledger = $this->ledgerPaidAmount($booking);
            if ($ledger > 0) {
                return $ledger;
            }

            return round((float) ($booking->rental_price ?? $booking->down_payment_amount ?? 0), 2);
        }

        return round((float) ($booking->down_payment_amount ?? 0), 2);
    }

    private function balancePaidAmount(BoatBooking $booking): float
    {
        if ($this->paidAsFullRental($booking)) {
            return 0.0;
        }

        if (! $booking->balanceIsSettled()) {
            return round(max((float) ($booking->remaining_balance ?? 0), 0), 2);
        }

        $stored = round((float) ($booking->remaining_balance ?? 0), 2);
        if ($stored > 0.009) {
            return $stored;
        }

        return round(max(
            (float) ($booking->rental_price ?? 0) - (float) ($booking->down_payment_amount ?? 0),
            0,
        ), 2);
    }

    private function totalPaidAmount(BoatBooking $booking): float
    {
        $ledger = $this->ledgerPaidAmount($booking);
        if ($ledger > 0) {
            return $ledger;
        }

        $total = 0.0;

        if ($booking->paymentStatus() === BookingPaymentStatus::Verified) {
            $total += $this->initialPaymentAmount($booking);
        }

        if ($booking->balanceIsSettled() && ! $this->paidAsFullRental($booking)) {
            $total += $this->balancePaidAmount($booking);
        }

        return round($total, 2);
    }
}
