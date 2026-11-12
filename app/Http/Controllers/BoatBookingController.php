<?php

namespace App\Http\Controllers;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\BoatStatus;
use App\Enums\GcashPayee;
use App\Enums\SafetyEquipment;
use App\Http\Requests\StoreBoatBookingRequest;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Services\BoatStatusSyncService;
use App\Services\ExpirePendingRentalBookingsService;
use App\Services\RentalCommissionService;
use App\Services\RentalCompletionService;
use App\Services\RentalPaymentService;
use App\Services\RentalPricingService;
use App\Services\RegularTripRotationService;
use App\Services\TripConfirmationService;
use App\Services\WeatherSuspensionService;
use App\Support\XenditBookingPresenter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoatBookingController extends Controller
{
    /**
     * @var list<string>
     */
    private const PACKAGES = [
        'Private island hopping',
        'Dapa ↔ General Luna charter',
        'Cloud 9 drop-off',
        'Sunset cruise',
    ];

    public function index(BoatStatusSyncService $boatStatus): Response
    {
        $boatStatus->syncOrganization(auth()->user()?->organization_id);

        $today = now('Asia/Manila')->toDateString();
        $boardStatuses = array_map(
            fn (BoatBookingStatus $status) => $status->value,
            BoatBookingStatus::rentalBoard(),
        );
        $activeTodayStatuses = array_values(array_filter(
            $boardStatuses,
            fn (string $status) => ! in_array($status, [
                BoatBookingStatus::Completed->value,
                BoatBookingStatus::PassengerNoShow->value,
            ], true),
        ));

        $todaysBookings = BoatBooking::query()
            ->with('boat')
            ->whereDate('booking_date', $today)
            ->whereIn('status', $activeTodayStatuses)
            ->get()
            ->keyBy('boat_id');

        $availableBoats = Boat::query()
            ->with(['operator', 'pictures', 'organization.routes', 'rentalDestinations'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Boat $boat) => $boat->isListedForPrivateBooking())
            ->values()
            ->map(fn (Boat $boat) => $this->transformBoat(
                $boat,
                $todaysBookings->get($boat->id),
            ));

        $scheduleRentals = BoatBooking::query()
            ->with(['boat.operator', 'passengers'])
            ->whereDate('booking_date', '>=', $today)
            ->whereIn('status', $boardStatuses)
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $todayRentals = $scheduleRentals
            ->filter(fn (BoatBooking $booking) => $booking->booking_date->toDateString() === $today)
            ->values();

        $upcomingRentals = $scheduleRentals
            ->filter(fn (BoatBooking $booking) => $booking->booking_date->toDateString() > $today
                && in_array($booking->status->value, $activeTodayStatuses, true))
            ->values();

        $calendarBookings = $scheduleRentals
            ->map(fn (BoatBooking $booking) => $this->transformBooking($booking))
            ->values();

        $bookingBoats = $availableBoats->map(fn (array $boat) => [
            'id' => $boat['id'],
            'name' => $boat['name'],
            'code' => $boat['code'],
            'capacity' => $boat['capacity'],
            'picture_url' => $boat['picture_url'],
            'operator_name' => $boat['operator']['full_name'] ?? null,
            'rental_destinations' => $boat['rental_destinations'] ?? [],
        ]);

        $organizationId = auth()->user()?->organization_id;
        $commissionPreview = app(RentalCommissionService::class)->breakdownForOrganization($organizationId, 1);

        $tripConfirmation = app(TripConfirmationService::class);
        $upcoming = $todayRentals
            ->map(function (BoatBooking $booking) use ($tripConfirmation) {
                $row = $this->transformBooking($booking);

                return array_merge($row, $tripConfirmation->adminTripRow($booking));
            })
            ->values();

        return Inertia::render('schedules/BoatBookingSchedule', [
            'availableBoats' => $availableBoats,
            'upcomingBookings' => $upcoming,
            'calendarBookings' => $calendarBookings,
            'bookingBoats' => $bookingBoats,
            'packageOptions' => self::PACKAGES,
            'statusOptions' => collect(BoatBookingStatus::cases())->map(fn (BoatBookingStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'walkInCommission' => [
                'enabled' => $commissionPreview['enabled'],
                'percentage' => $commissionPreview['percentage'],
                'percentage_label' => $commissionPreview['percentage_label'],
            ],
            'stats' => [
                'assigned' => $availableBoats->count(),
                'open' => $availableBoats->where('is_open_for_booking', true)->count(),
                'upcoming' => $todayRentals->count(),
                'passengers' => $todayRentals->sum('passenger_count'),
                'upcoming_total' => $upcomingRentals->count(),
            ],
            'weatherAlert' => WeatherSuspensionController::activeTodayAlert(),
            'weatherWatchAlert' => WeatherSuspensionController::activeWatchAlert(),
            'weatherHoldDates' => WeatherSuspensionController::activeHoldDates(),
        ]);
    }

    public function requests(): Response
    {
        app(\App\Services\OperatorNoShowService::class)->syncPendingOperatorRescheduleBookingStatuses();
        app(\App\Services\RentalCommissionService::class)->syncFullRentalPayments((int) auth()->user()?->organization_id);
        app(ExpirePendingRentalBookingsService::class)->syncExpiredRentalBookings();

        $bookings = BoatBooking::query()
            ->with(['boat.operator', 'boat.pictures', 'passengers', 'activities', 'reschedules.requester', 'reschedules.reviewer', 'cancelledBy', 'paymentVerifiedBy', 'balanceRecordedBy', 'commission', 'operatorNoShowReports', 'passengerNoShowReport.reporter', 'refundRequests'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('bookings/Requests', [
            'bookings' => $bookings->map(fn (BoatBooking $booking) => $this->transformBooking($booking)),
            'newBookingIds' => app(\App\Services\AdminNotificationFeed::class)->unreadActionableBookingIds(auth()->user()),
            'stats' => [
                'total' => $bookings->count(),
                'pending' => $bookings->whereIn('status', [
                    BoatBookingStatus::Pending,
                    BoatBookingStatus::ReschedulePending,
                ])->count(),
                'approved' => $bookings->whereIn('status', [
                    BoatBookingStatus::AwaitingPayment,
                    BoatBookingStatus::Confirmed,
                    BoatBookingStatus::Ongoing,
                    BoatBookingStatus::WeatherHold,
                ])->count(),
                'reschedule_pending' => $bookings->where('status', BoatBookingStatus::ReschedulePending)->count(),
                'completed' => $bookings->where('status', BoatBookingStatus::Completed)->count(),
                'cancelled' => $bookings->whereIn('status', [
                    BoatBookingStatus::Cancelled,
                    BoatBookingStatus::Rejected,
                ])->count(),
            ],
        ]);
    }

    public function show(Request $request, BoatBooking $boatBooking): Response
    {
        app(\App\Services\AdminNotificationFeed::class)->acknowledgeBooking($request->user(), $boatBooking->id);
        app(\App\Services\OperatorNoShowService::class)->syncPendingOperatorRescheduleBookingStatuses();
        app(\App\Services\RentalCommissionService::class)->syncFullRentalPayments((int) $request->user()?->organization_id);
        $boatBooking->refresh();

        $boatBooking->load(['boat.operator.user', 'boat.organization', 'passengers', 'activities', 'reschedules.requester', 'reschedules.reviewer', 'cancelledBy', 'paymentVerifiedBy', 'balanceRecordedBy', 'commission']);
        $boatBooking->backfillMissingApprovalActivity();
        $boatBooking->load('activities');

        app(\App\Services\RentalCommissionService::class)->generateIfEligible($boatBooking);
        $boatBooking->load('commission');

        $noShowReport = $boatBooking->operatorNoShowReports()->latest('id')->first();

        $refunds = app(\App\Services\RentalRefundService::class);
        $refundRequest = $refunds->latestRequest($boatBooking);
        if ($refundRequest?->status === \App\Models\RentalRefundRequest::STATUS_REFUNDING) {
            $refundRequest = $refunds->syncPending($refundRequest);
        }

        $noShowRecord = $noShowReport?->toAdminRecord();
        $noShowOperator = $noShowReport?->booking?->boat?->operator;
        if ($noShowRecord && $noShowOperator) {
            $noShowRecord['operator_violation_count'] = app(\App\Services\OperatorNoShowViolationService::class)->confirmedStrikeCount($noShowOperator);
            $noShowRecord['operator_violation_max'] = \App\Services\OperatorNoShowViolationService::MAX_STRIKES;
        }
        // The no-show case card already shows and runs its own refund.
        if ($refundRequest?->type === \App\Models\RentalRefundRequest::TYPE_OPERATOR_NO_SHOW) {
            $refundRequest = null;
        }
        $passengerNoShow = $boatBooking->passengerNoShowReport;

        return Inertia::render('bookings/Show', [
            'booking' => $this->transformBooking($boatBooking),
            'no_show_report' => $noShowRecord,
            'passenger_no_show_report' => $passengerNoShow ? [
                ...$passengerNoShow->toPayload(),
                'can_resolve' => $passengerNoShow->isDisputed(),
            ] : null,
            'refund_request' => $refunds->adminPayload($refundRequest),
            'can_manage' => $request->user()?->role === \App\Enums\UserRole::Administrator,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function noShowIncidentSummary(BoatBooking $booking): ?array
    {
        $passenger = $booking->relationLoaded('passengerNoShowReport')
            ? $booking->passengerNoShowReport
            : $booking->passengerNoShowReport()->first();

        if ($passenger instanceof \App\Models\PassengerNoShowReport) {
            return [
                'kind' => 'passenger',
                'label' => 'Passenger No-Show',
                'status' => $passenger->status,
                'status_label' => match ($passenger->status) {
                    \App\Models\PassengerNoShowReport::STATUS_DISPUTED => 'Disputed · needs review',
                    \App\Models\PassengerNoShowReport::STATUS_REVERSED => 'Reversed',
                    default => 'Cancelled · no action needed',
                },
                'needs_action' => $passenger->isDisputed(),
                'active' => $passenger->isDisputed(),
                'reported_at_label' => $passenger->reported_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
                'reported_by' => $passenger->reporter?->name,
                'amount_paid_label' => '₱'.number_format((float) $passenger->amount_paid, 2),
                'dispute_status_label' => $passenger->disputeStatusLabel(),
            ];
        }

        if ($booking->status === BoatBookingStatus::PassengerNoShow) {
            return [
                'kind' => 'passenger',
                'label' => 'Passenger No-Show',
                'status' => \App\Models\PassengerNoShowReport::STATUS_CONFIRMED,
                'status_label' => 'Cancelled · no action needed',
                'needs_action' => false,
                'active' => false,
                'reported_at_label' => $booking->cancelled_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
                'reported_by' => 'Boat Operator',
                'amount_paid_label' => null,
                'dispute_status_label' => null,
            ];
        }

        $operator = $booking->relationLoaded('operatorNoShowReports')
            ? $booking->operatorNoShowReports->sortByDesc('id')->first()
            : $booking->operatorNoShowReports()->latest('id')->first();

        if (! $operator instanceof \App\Models\OperatorNoShowReport) {
            return null;
        }

        $display = $operator->incidentDisplayStatus();

        return [
            'kind' => 'operator',
            'label' => 'Boat / Operator No-Show',
            'status' => $display->value,
            'status_label' => $display->label(),
            'needs_action' => $operator->isOpen(),
            'active' => $operator->isOpen(),
            'reported_at_label' => $operator->reported_at?->format('M d, Y · g:i A'),
            'reported_by' => $booking->customer_name,
            'amount_paid_label' => null,
            'dispute_status_label' => null,
        ];
    }

    public function receipt(BoatBooking $boatBooking): StreamedResponse
    {
        $path = $boatBooking->payment_receipt_path;

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

    public function verifyPayment(Request $request, BoatBooking $boatBooking, RentalPaymentService $payments): RedirectResponse
    {
        $payments->authorizeVerifier($request->user(), $boatBooking);

        $input = $request->validate([
            'confirmed_gcash_transaction' => ['required', 'accepted'],
        ]);

        $payments->verify($boatBooking, $request->user(), $input);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Down payment verified. Record the remaining balance on this same page if it already arrived.',
        ]);

        return to_route('bookings.requests.show', $boatBooking);
    }

    public function rejectPayment(Request $request, BoatBooking $boatBooking, RentalPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $payments->reject($boatBooking, $request->user(), $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment proof rejected. The passenger can resubmit.']);

        return back();
    }

    public function recordBalance(Request $request, BoatBooking $boatBooking, RentalPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'gcash'])],
            'reference' => ['nullable', 'string', 'min:6', 'max:80'],
            'confirmed' => ['accepted'],
        ]);

        $payments->recordBalance(
            $boatBooking,
            $request->user(),
            $data['method'],
            $data['reference'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Remaining balance recorded.']);

        return back();
    }

    public function complete(Request $request, BoatBooking $boatBooking, RentalCompletionService $completion): RedirectResponse
    {
        abort_unless($request->user()?->role === \App\Enums\UserRole::Administrator, 403);

        if ($boatBooking->booking_date?->isAfter(now('Asia/Manila')->endOfDay())) {
            throw ValidationException::withMessages([
                'booking' => 'This trip is scheduled for '.$boatBooking->booking_date->format('M d, Y').'. Complete it on or after the trip date.',
            ]);
        }

        $settlesBalance = $boatBooking->canRecordBalancePayment();
        $completion->complete($boatBooking, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $settlesBalance
                ? 'Trip completed. The remaining balance was recorded as paid in cash during the trip.'
                : 'Trip completed. The operator share is now ready for payout.',
        ]);

        return back();
    }

    public function store(StoreBoatBookingRequest $request, RentalPricingService $pricing): RedirectResponse
    {
        $data = $request->validated();
        $walkIn = $request->boolean('walk_in');
        $boat = Boat::query()->with('rentalDestinations')->findOrFail($data['boat_id']);
        $data['package'] = $data['package'] ?: $data['destination'];

        $pricingSnapshot = $pricing->snapshotForDestination($boat, $data['destination']);
        $payload = [
            ...$data,
            ...$pricingSnapshot,
            ...app(RentalCommissionService::class)->snapshotForBooking(
                $request->user()?->organization_id,
                isset($pricingSnapshot['rental_price']) ? (float) $pricingSnapshot['rental_price'] : null,
                isset($pricingSnapshot['down_payment_amount']) ? (float) $pricingSnapshot['down_payment_amount'] : null,
            ),
        ];

        $receipt = $request->file('payment_receipt');

        if ($walkIn) {
            $method = (string) $data['walk_in_payment_method'];
            $downPayment = (float) ($pricingSnapshot['down_payment_amount'] ?? 0);

            if ($method === 'cash') {
                $received = round((float) ($data['walk_in_cash_received'] ?? 0), 2);
                $due = round($downPayment, 2);
                $change = round(max(0, $received - $due), 2);
                $walkInMeta = sprintf('@walk-in:cash;received=%.2f;change=%.2f', $received, $change);

                $payload = [
                    ...$payload,
                    'user_id' => null,
                    'status' => BoatBookingStatus::Confirmed,
                    'payment_status' => BookingPaymentStatus::Verified,
                    'payment_verified_at' => now(),
                    'payment_verified_by' => $request->user()?->id,
                    'payment_submitted_at' => now(),
                    'payment_amount_submitted' => $due > 0 ? $due : null,
                    'gcash_payee' => GcashPayee::Barangay,
                    'gcash_reference' => null,
                    'remarks' => trim(collect([
                        $data['remarks'] ?? null,
                        $walkInMeta,
                    ])->filter()->implode("\n")),
                ];
            } else {
                $payload = [
                    ...$payload,
                    'user_id' => null,
                    'status' => BoatBookingStatus::AwaitingPayment,
                    'payment_status' => BookingPaymentStatus::ForVerification,
                    'payment_submitted_at' => now(),
                    'payment_amount_submitted' => $downPayment > 0 ? $downPayment : null,
                    'gcash_payee' => GcashPayee::Barangay,
                    'gcash_reference' => trim((string) ($data['gcash_reference'] ?? '')),
                    'remarks' => trim(collect([
                        $data['remarks'] ?? null,
                        '@walk-in:gcash',
                    ])->filter()->implode("\n")),
                ];
            }
        }

        unset(
            $payload['walk_in'],
            $payload['walk_in_payment_method'],
            $payload['walk_in_cash_received'],
            $payload['payment_receipt'],
        );

        $booking = BoatBooking::createWithUniqueCode($payload);

        if ($walkIn && ($data['walk_in_payment_method'] ?? '') === 'gcash' && $receipt) {
            $path = $receipt->store(
                'rental-receipts/'.$booking->organization_id.'/'.$booking->id,
                'local',
            );
            $booking->update(['payment_receipt_path' => $path]);
        }

        if ($walkIn && ($data['walk_in_payment_method'] ?? '') === 'cash') {
            app(\App\Services\RentalCommissionService::class)->generateIfEligible(
                $booking->fresh(['boat.operator']),
            );
        }

        $this->recordHistory($booking);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $walkIn
                ? (($data['walk_in_payment_method'] ?? '') === 'cash'
                    ? 'Walk-in rental saved. Cash payment recorded.'
                    : 'Walk-in rental saved. GCash payment is awaiting verification.')
                : 'Private booking added to the schedule.',
        ]);

        return to_route('schedules.boat-booking-schedule');
    }

    public function update(StoreBoatBookingRequest $request, BoatBooking $boatBooking): RedirectResponse
    {
        $data = $request->validated();
        $data['package'] = $data['package'] ?: $data['destination'];

        if (! $boatBooking->commission) {
            $data = [
                ...$data,
                ...app(\App\Services\RentalCommissionService::class)->snapshotForBooking(
                    $request->user()?->organization_id,
                    isset($data['rental_price']) ? (float) $data['rental_price'] : (float) $boatBooking->rental_price,
                    isset($data['down_payment_amount'])
                        ? (float) $data['down_payment_amount']
                        : (float) ($boatBooking->down_payment_amount ?? 0),
                ),
            ];
        }

        $boatBooking->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking schedule updated.']);

        return to_route('schedules.boat-booking-schedule');
    }

    public function review(Request $request, BoatBooking $boatBooking, WeatherSuspensionService $weather): RedirectResponse
    {
        $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'cancel'])],
            'cancellation_reason' => [
                'required_if:action,reject',
                'required_if:action,cancel',
                'nullable',
                'string',
                'min:5',
                'max:500',
            ],
        ]);

        if ($request->string('action')->toString() === 'approve') {
            return $this->approvePendingRental($request, $boatBooking, $weather);
        }

        if (! $boatBooking->canBeCancelledByAdmin()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $boatBooking->canBeCancelled()
                    ? 'Confirmed rentals can no longer be cancelled here.'
                    : 'Completed or cancelled rentals cannot be cancelled.',
            ]);

            return back();
        }

        $status = $boatBooking->status === BoatBookingStatus::Pending
            ? BoatBookingStatus::Rejected
            : BoatBookingStatus::Cancelled;

        $boatBooking->update([
            'status' => $status,
            'cancellation_reason' => $request->string('cancellation_reason')->trim()->value(),
            'cancelled_by' => $request->user()?->id,
            'cancelled_at' => now(),
        ]);

        $refundCase = $status === BoatBookingStatus::Cancelled
            ? app(\App\Services\RentalRefundService::class)->openForCancellation(
                $boatBooking,
                $request->user(),
                \App\Models\RentalRefundRequest::TYPE_ADMIN_CANCELLED,
            )
            : null;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match (true) {
                $status === BoatBookingStatus::Rejected => 'Rental request rejected. The operator and passenger will be notified.',
                $refundCase !== null => 'Rental cancelled. The passenger already paid and can now choose a full refund or a new date.',
                default => 'Rental cancelled. The operator and passenger will be notified.',
            },
        ]);

        return back();
    }

    private function approvePendingRental(
        Request $request,
        BoatBooking $boatBooking,
        WeatherSuspensionService $weather,
    ): RedirectResponse {
        if (! $boatBooking->canBeApprovedByAdmin()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Only a pending passenger request can be approved here.',
            ]);

            return back();
        }

        $boatBooking->loadMissing('boat.operator');
        $date = $boatBooking->booking_date->toDateString();
        $time = Carbon::parse($boatBooking->start_time)->format('H:i');

        if ($boatBooking->boat) {
            app(RegularTripRotationService::class)->releaseBoatForPrivateBooking($boatBooking->boat, $date);
            $boatBooking->setRelation('boat', $boatBooking->boat->fresh());
        }

        $conflict = $boatBooking->boat?->slotConflictMessage($date, $time, $boatBooking->id);

        if ($conflict) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $conflict]);

            return back();
        }

        $weatherBlock = $weather->rentalBlockMessage(
            (int) ($boatBooking->organization_id ?: $boatBooking->boat?->organization_id),
            $date,
            $boatBooking->destination ?: $boatBooking->package,
            $time,
        );

        if ($weatherBlock) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $weatherBlock]);

            return back();
        }

        $next = $boatBooking->requiresDownPayment()
            ? BoatBookingStatus::AwaitingPayment
            : BoatBookingStatus::Confirmed;
        $payload = ['status' => $next];

        if ($next === BoatBookingStatus::AwaitingPayment) {
            $boatBooking->snapshotPaymentRecipient();
            $payload['gcash_payee'] = $boatBooking->gcashPayee();
            $payload['gcash_recipient_account_name'] = $boatBooking->gcash_recipient_account_name;
            $payload['gcash_recipient_mobile'] = $boatBooking->gcash_recipient_mobile;
        }

        $boatBooking->update($payload);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $next === BoatBookingStatus::AwaitingPayment
                ? 'Rental approved. The passenger can now pay the down payment.'
                : 'Rental approved on behalf of the operator.',
        ]);

        return back();
    }

    public function reviewReschedule(Request $request, BoatBooking $boatBooking, WeatherSuspensionService $weather): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'min:5', 'max:500'],
        ]);

        if ($data['action'] === 'approve') {
            $weather->approveReschedule($boatBooking, $request->user());
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Reschedule approved. The verified downpayment was carried forward.',
            ]);
        } else {
            $weather->rejectReschedule($boatBooking, $request->user(), (string) $data['rejection_reason']);
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Reschedule rejected. The rental stays on Weather Hold.',
            ]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBoat(Boat $boat, ?BoatBooking $todaysBooking): array
    {
        $isOpen = $boat->isOpenForPrivateBookingOn(now()->toDateString());

        $availabilityLabel = match (true) {
            $todaysBooking !== null => 'Booked today',
            $boat->status === BoatStatus::OnDuty && $isOpen => 'On duty — open for booking',
            $isOpen => 'Available for booking',
            $boat->status === BoatStatus::OnTrip => 'On trip — not available today',
            $boat->status === BoatStatus::Maintenance => 'Maintenance',
            $boat->status === BoatStatus::Unavailable => 'Unavailable',
            default => $boat->status->label(),
        };

        $safety = SafetyEquipment::summarize($boat->safety_equipment, $boat->life_jacket_quantity);
        $location = $boat->organization?->rentalAreaLabel() ?? 'Siargao Islands';

        return [
            'id' => $boat->id,
            'name' => $boat->name,
            'code' => $boat->code,
            'type' => $boat->type ?: 'Pump Boat',
            'capacity' => $boat->capacity,
            'license_no' => $boat->license_no,
            'registration_date' => $boat->registration_date?->toDateString(),
            'registration_date_label' => $boat->registration_date?->format('M d, Y'),
            'status' => $boat->status->value,
            'status_label' => $boat->status->label(),
            'registration_status' => $boat->registration_status->value,
            'registration_status_label' => $boat->registration_status->label(),
            'service_type' => $boat->syncedServiceType(),
            'service_type_label' => $boat->serviceAvailabilityLabel(),
            'service_availability' => $boat->selectedServices(),
            'service_availability_label' => $boat->serviceAvailabilityLabel(),
            'safety_information' => $boat->safety_information,
            'safety_equipment' => $safety ? explode("\n", $safety) : [],
            'life_jacket_quantity' => $boat->life_jacket_quantity,
            'rental_destinations' => $boat->rentalDestinations
                ->filter(fn ($route) => $route->isExclusiveBoatRental())
                ->sortBy('destination')
                ->map(fn ($route) => $route->toRentalDestination($location))
                ->values()
                ->all(),
            'is_active' => $boat->is_active,
            'picture_url' => $boat->pictureUrl(),
            'pictures' => $boat->picturePayload(),
            'home_port' => $boat->organization?->homePort() ?? 'Lakandula',
            'is_open_for_booking' => $isOpen,
            'availability_label' => $availabilityLabel,
            'operator' => $boat->operator ? [
                'full_name' => $boat->operator->full_name,
                'contact_number' => $boat->operator->contact_number,
                'picture_url' => $boat->operator->pictureUrl(),
                'admin_use_only' => $boat->operator->admin_use_only,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBooking(BoatBooking $booking): array
    {
        $time = Carbon::parse($booking->start_time);
        $submitted = $booking->hasPaymentSubmission();

        return [
            'id' => $booking->id,
            'code' => $booking->code ?? sprintf('BR-%s-%05d', $booking->created_at?->format('Y') ?? now()->year, $booking->id),
            'customer_name' => $booking->customer_name,
            'contact_number' => $booking->contact_number,
            'package' => $booking->package,
            'destination' => $booking->destination ?: $booking->package,
            'pickup_point' => $booking->pickup_point,
            'purpose' => $booking->purpose,
            'booking_date' => $booking->booking_date->toDateString(),
            'booking_date_label' => $booking->booking_date->format('M d, Y'),
            'original_booking_date' => ($booking->original_booking_date ?? $booking->booking_date)->toDateString(),
            'original_booking_date_label' => ($booking->original_booking_date ?? $booking->booking_date)->format('M d, Y'),
            'weather_reason' => $booking->weather_reason,
            'reschedule_status' => $booking->reschedule_status,
            'reschedule_status_label' => match ($booking->reschedule_status) {
                'awaiting_passenger' => 'Awaiting Passenger Selection',
                'pending_approval' => 'Pending Approval',
                'approved' => 'Rescheduled',
                'rejected' => 'Rejected — choose another date',
                default => null,
            },
            'requested_booking_date' => $booking->requested_booking_date?->toDateString(),
            'requested_booking_date_label' => $booking->requested_booking_date?->format('M d, Y'),
            'reschedule_rejection_reason' => $booking->reschedule_rejection_reason,
            'can_review_reschedule' => $booking->canReviewReschedule(),
            'reschedule_history' => $booking->relationLoaded('reschedules')
                ? $booking->reschedules->map(fn ($item) => $item->toRecord())->values()->all()
                : [],
            'start_time' => $time->format('H:i'),
            'start_time_label' => $time->format('g:i A'),
            'created_at' => $booking->created_at?->toIso8601String(),
            'created_at_unix' => $booking->created_at?->getTimestamp() ?? $booking->id,
            'created_at_label' => $booking->created_at?->format('M d, Y'),
            'created_at_time_label' => $booking->created_at?->format('g:i A'),
            'passenger_count' => $booking->passenger_count,
            'status' => $booking->status->value,
            'display_status' => $booking->isRescheduleConfirmed()
                ? 'reschedule_confirmed'
                : $booking->status->value,
            'weather_reschedule_confirmed' => $booking->isWeatherRescheduleConfirmed(),
            'status_label' => $booking->adminStatusLabel(),
            'remarks' => $booking->remarks,
            'remarks_display' => $this->displayRemarks($booking->remarks),
            'can_cancel' => $booking->canBeCancelledByAdmin(),
            'no_show_incident' => $this->noShowIncidentSummary($booking),
            'is_refunded' => $booking->operatorNoShowRefundCompleted()
                || ($booking->relationLoaded('refundRequests') && $booking->refundRequests->contains(
                    fn ($refund) => $refund->status === \App\Models\RentalRefundRequest::STATUS_REFUNDED,
                )),
            'can_approve' => $booking->canBeApprovedByAdmin(),
            'can_complete' => $booking->canCompleteTrip()
                && ! $booking->booking_date?->isAfter(now('Asia/Manila')->endOfDay()),
            'complete_blocked_reason' => in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)
                ? (app(RentalCompletionService::class)->blockedReason($booking)
                    ?? ($booking->booking_date?->isAfter(now('Asia/Manila')->endOfDay())
                        ? 'Available on the trip date ('.$booking->booking_date->format('M d, Y').').'
                        : null))
                : null,
            'complete_settles_balance' => $booking->canRecordBalancePayment(),
            'cancellation_reason' => $booking->cancellation_reason,
            'cancelled_by_name' => $booking->cancelledBy?->name,
            'cancelled_at_label' => $booking->cancelled_at?->format('M d, Y · g:i A'),
            ...$this->walkInContext($booking),
            'passengers' => $booking->passengers
                ->map(fn ($passenger) => $passenger->toRecord())
                ->values()
                ->all(),
            'payment' => [
                'submitted' => $submitted,
                'rental_price' => $booking->rental_price,
                'rental_price_label' => $this->peso($booking->rental_price),
                'down_payment_percent' => $booking->down_payment_percent,
                'down_payment_percent_label' => $booking->down_payment_percent !== null
                    ? rtrim(rtrim(number_format((float) $booking->down_payment_percent, 2, '.', ''), '0'), '.').'%'
                    : null,
                'down_payment_amount' => $booking->down_payment_amount,
                'down_payment_amount_label' => $this->peso($booking->down_payment_amount),
                'remaining_balance' => $booking->remaining_balance,
                'remaining_balance_label' => $this->peso($booking->remaining_balance),
                'payment_status' => $booking->paymentStatus()->value,
                'payment_status_label' => $booking->paymentStatus()->label(),
                'payment_rejection_reason' => $booking->payment_rejection_reason,
                'gcash_reference' => $booking->gcash_reference,
                'receipt_url' => $submitted ? '/boat-bookings/'.$booking->id.'/receipt' : null,
                'receipt_filename' => $submitted ? 'GCash receipt' : null,
                'payment_submitted_at_label' => $booking->payment_submitted_at?->format('M d, Y · g:i A'),
                'payment_verified_at_label' => $booking->payment_verified_at?->format('M d, Y · g:i A'),
                'payment_verified_by_name' => $booking->paymentVerifiedBy?->name,
                'balance_status' => $booking->balanceStatus()->value,
                'balance_status_label' => $booking->balanceStatus()->label(),
                'balance_method' => $booking->balance_method,
                'balance_method_label' => match ($booking->balance_method) {
                    'cash' => 'Cash',
                    'gcash' => 'GCash',
                    default => null,
                },
                'balance_reference' => $booking->balance_reference,
                'balance_paid_at_label' => $booking->balance_paid_at?->format('M d, Y · g:i A'),
                'balance_recorded_by_name' => $booking->balanceRecordedBy?->name,
                'overall_status' => $booking->overallPaymentStatus(),
                'overall_status_label' => $booking->overallPaymentStatusLabel(),
                'commission_enabled' => (bool) $booking->commission_enabled,
                'commission_percentage_label' => $booking->commission_percentage !== null
                    ? OrganizationRoute::formatPercent((float) $booking->commission_percentage)
                    : null,
                'commission_amount_label' => $booking->commission_amount !== null
                    ? $this->peso($booking->commission_amount)
                    : null,
                'operator_net_label' => $booking->operator_net_amount !== null
                    ? $this->peso($booking->operator_net_amount)
                    : null,
                'gcash_payee' => $booking->gcashPayee()->value,
                'gcash_payee_label' => $booking->gcashPayee()->label(),
                'gcash_recipient_account_name' => $booking->gcash_recipient_account_name,
                'gcash_recipient_mobile' => $booking->gcash_recipient_mobile,
                'payment_amount_submitted' => $booking->payment_amount_submitted,
                'payment_amount_submitted_label' => $this->peso($booking->payment_amount_submitted),
                'can_verify' => $booking->canVerifyPayment() && $booking->paysBarangayGcash(),
                'can_reject' => $booking->canVerifyPayment() && $booking->paysBarangayGcash(),
                'can_record_balance' => false,
                'commission_id' => $booking->commission?->id,
                'commission_status' => $booking->commission?->settlement_status?->value,
                'commission_status_label' => $booking->commission?->settlement_status?->label(),
                'commission_can_verify' => (bool) $booking->commission?->canVerify(),
                'read_only' => ! ($booking->paysBarangayGcash() && $booking->canVerifyPayment()),
                ...XenditBookingPresenter::forBooking($booking),
            ],
            'activities' => $booking->activityTimeline(),
            'feedback' => $booking->feedback?->toPayload(),
            'boat' => $this->transformBookingBoat($booking->boat),
            ...app(TripConfirmationService::class)->adminTripRow($booking),
        ];
    }

    /**
     * Boat snapshot for admin rental details — aligned with passenger mobile rent screen.
     *
     * @return array<string, mixed>
     */
    private function transformBookingBoat(Boat $boat): array
    {
        $safety = SafetyEquipment::summarize($boat->safety_equipment, $boat->life_jacket_quantity);

        return [
            'id' => $boat->id,
            'name' => $boat->name,
            'code' => $boat->code,
            'type' => $boat->type ?: 'Pump Boat',
            'capacity' => $boat->capacity,
            'license_no' => $boat->license_no,
            'registration_date_label' => $boat->registration_date?->format('M d, Y'),
            'home_port' => $boat->organization?->homePort() ?? 'Lakandula',
            'safety_information' => $boat->safety_information,
            'safety_equipment' => $safety ? explode("\n", $safety) : [],
            'picture_url' => $boat->pictureUrl(),
            'operator_name' => $boat->operator?->full_name,
            'operator_contact_number' => $boat->operator?->contact_number,
        ];
    }

    private function peso(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return '₱'.number_format((float) $amount, 2);
    }

    /**
     * @return array{
     *     walk_in: bool,
     *     walk_in_payment_method: string|null,
     *     walk_in_cash_received_label: string|null,
     *     walk_in_change_label: string|null
     * }
     */
    private function walkInContext(BoatBooking $booking): array
    {
        $remarks = (string) ($booking->remarks ?? '');
        $walkIn = $booking->user_id === null && (
            str_contains($remarks, '@walk-in:')
            || str_contains($remarks, 'Walk-in down payment received in cash')
            || str_contains($remarks, 'Walk-in GCash payment submitted')
        );

        if (! $walkIn) {
            return [
                'walk_in' => false,
                'walk_in_payment_method' => null,
                'walk_in_cash_received_label' => null,
                'walk_in_change_label' => null,
            ];
        }

        $cash = str_contains($remarks, '@walk-in:cash')
            || str_contains($remarks, 'Walk-in down payment received in cash');
        $receivedLabel = null;
        $changeLabel = null;

        if ($cash) {
            if (preg_match('/@walk-in:cash;received=([\d.]+);change=([\d.]+)/', $remarks, $match)) {
                $receivedLabel = $this->peso($match[1]);
                $changeLabel = $this->peso($match[2]);
            } else {
                if (preg_match('/Cash received:\s*(.+?)\./', $remarks, $match)) {
                    $receivedLabel = trim($match[1]);
                }
                if (preg_match('/Change given:\s*(.+?)\./', $remarks, $match)) {
                    $changeLabel = trim($match[1]);
                }
            }
        }

        return [
            'walk_in' => true,
            'walk_in_payment_method' => $cash ? 'cash' : 'gcash',
            'walk_in_cash_received_label' => $receivedLabel,
            'walk_in_change_label' => $changeLabel,
        ];
    }

    private function displayRemarks(?string $remarks): ?string
    {
        if ($remarks === null || trim($remarks) === '') {
            return null;
        }

        $text = $remarks;

        $text = (string) preg_replace('/@walk-in:[^\r\n]*/', '', $text);
        $text = (string) preg_replace(
            '/Walk-in down payment received in cash at the barangay office\.\s*/',
            '',
            $text,
        );
        $text = (string) preg_replace('/Cash received:\s*₱[\d,]+\.\d{2}\.\s*/', '', $text);
        $text = (string) preg_replace('/Change given:\s*₱[\d,]+\.\d{2}\.\s*/', '', $text);
        $text = (string) preg_replace(
            '/Walk-in GCash payment submitted at the barangay office — awaiting verification\.\s*/',
            '',
            $text,
        );

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));

        if ($lines === []) {
            return null;
        }

        return implode("\n", $lines);
    }

    private function recordHistory(BoatBooking $booking): void
    {
        $booking->loadMissing('boat');

        $booking->boat->histories()->create([
            'category' => 'rental',
            'title' => $booking->package,
            'occurred_on' => $booking->booking_date,
            'details' => 'Booked rental for '.$booking->customer_name.'.',
        ]);
    }
}
