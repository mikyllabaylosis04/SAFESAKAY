<?php

namespace App\Models;

use App\Enums\BoatBookingStatus;
use App\Enums\OperatorNoShowRescheduleStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\TripConfirmationMethod;
use App\Enums\TripConfirmationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string $code
 * @property int $boat_id
 * @property int|null $user_id
 * @property string $customer_name
 * @property string|null $contact_number
 * @property string $package
 * @property string|null $destination
 * @property string|null $pickup_point
 * @property string|null $purpose
 * @property Carbon $booking_date
 * @property Carbon|null $original_booking_date
 * @property Carbon $start_time
 * @property int $passenger_count
 * @property BoatBookingStatus $status
 * @property string|null $remarks
 * @property string|null $rental_price
 * @property string|null $down_payment_percent
 * @property string|null $down_payment_amount
 * @property string|null $remaining_balance
 * @property BookingPaymentStatus $payment_status
 * @property string|null $cancellation_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $gcash_reference
 * @property string|null $payment_receipt_path
 * @property Carbon|null $payment_submitted_at
 * @property Carbon|null $payment_verified_at
 * @property int|null $payment_verified_by
 * @property string|null $payment_rejection_reason
 * @property BookingBalanceStatus $balance_status
 * @property string|null $balance_method
 * @property string|null $balance_reference
 * @property Carbon|null $balance_paid_at
 * @property int|null $balance_recorded_by
 * @property Carbon|null $completed_at
 * @property int|null $weather_suspension_id
 * @property Carbon|null $weather_hold_at
 * @property string|null $weather_reason
 * @property bool $weather_review_required
 * @property string|null $reschedule_status
 * @property Carbon|null $requested_booking_date
 * @property string|null $reschedule_rejection_reason
 * @property string|null $gcash_recipient_account_name
 * @property string|null $gcash_recipient_mobile
 * @property string|null $payment_amount_submitted
 */
#[Fillable([
    'organization_id',
    'code',
    'boat_id',
    'user_id',
    'customer_name',
    'contact_number',
    'package',
    'destination',
    'pickup_point',
    'purpose',
    'booking_date',
    'original_booking_date',
    'start_time',
    'passenger_count',
    'status',
    'remarks',
    'rental_price',
    'down_payment_percent',
    'down_payment_amount',
    'remaining_balance',
    'commission_enabled',
    'commission_percentage',
    'commission_amount',
    'operator_net_amount',
    'gcash_payee',
    'gcash_recipient_account_name',
    'gcash_recipient_mobile',
    'payment_amount_submitted',
    'payment_status',
    'cancellation_reason',
    'cancelled_by',
    'cancelled_at',
    'gcash_reference',
    'payment_receipt_path',
    'payment_submitted_at',
    'payment_verified_at',
    'payment_verified_by',
    'payment_rejection_reason',
    'balance_status',
    'balance_method',
    'balance_reference',
    'balance_paid_at',
    'balance_recorded_by',
    'completed_at',
    'passenger_no_show_admin_acknowledged_at',
    'passenger_no_show_admin_acknowledged_by',
    'operator_availability_confirmed_at',
    'operator_availability_confirmed_by',
    'trip_confirmation_status',
    'trip_confirmed_at',
    'trip_confirmed_by_user_id',
    'trip_confirmation_method',
    'trip_confirmation_remarks',
    'trip_confirmation_conflict',
    'operator_cannot_operate_reason',
    'operator_meeting_point_arrived_at',
    'operator_meeting_point_arrived_by_user_id',
    'weather_suspension_id',
    'weather_hold_at',
    'weather_reason',
    'weather_review_required',
    'reschedule_status',
    'requested_booking_date',
    'reschedule_rejection_reason',
])]
class BoatBooking extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'original_booking_date' => 'date',
            'start_time' => 'datetime:H:i',
            'requested_booking_date' => 'date',
            'weather_hold_at' => 'datetime',
            'weather_review_required' => 'boolean',
            'commission_enabled' => 'boolean',
            'gcash_payee' => GcashPayee::class,
            'passenger_count' => 'integer',
            'status' => BoatBookingStatus::class,
            'rental_price' => 'decimal:2',
            'down_payment_percent' => 'decimal:2',
            'down_payment_amount' => 'decimal:2',
            'payment_amount_submitted' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'payment_status' => BookingPaymentStatus::class,
            'cancelled_at' => 'datetime',
            'payment_submitted_at' => 'datetime',
            'payment_verified_at' => 'datetime',
            'balance_status' => BookingBalanceStatus::class,
            'balance_paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'passenger_no_show_admin_acknowledged_at' => 'datetime',
            'operator_availability_confirmed_at' => 'datetime',
            'trip_confirmation_status' => TripConfirmationStatus::class,
            'trip_confirmed_at' => 'datetime',
            'trip_confirmation_method' => TripConfirmationMethod::class,
            'trip_confirmation_conflict' => 'boolean',
            'operator_meeting_point_arrived_at' => 'datetime',
        ];
    }

    public static function nextCode(): string
    {
        $year = (int) now()->year;
        $prefix = sprintf('BR-%d-', $year);

        $lastSequence = static::query()
            ->withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->reduce(function (int $max, mixed $code) use ($prefix): int {
                $suffix = substr((string) $code, strlen($prefix));

                if ($suffix === '' || ! ctype_digit($suffix)) {
                    return $max;
                }

                return max($max, (int) $suffix);
            }, 0);

        return sprintf('BR-%d-%05d', $year, $lastSequence + 1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithUniqueCode(array $attributes): static
    {
        $lastException = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $attributes['code'] = static::nextCode();

                return static::query()->create($attributes);
            } catch (QueryException $exception) {
                $lastException = $exception;

                if (! str_contains($exception->getMessage(), 'boat_bookings_code_unique')) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to allocate a unique booking code.');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BookingPassenger, $this>
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<RentalPayment, $this>
     */
    public function rentalPayments(): HasMany
    {
        return $this->hasMany(RentalPayment::class, 'boat_booking_id');
    }

    /**
     * @return HasMany<RentalRefundRequest, $this>
     */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RentalRefundRequest::class, 'boat_booking_id');
    }

    /**
     * @return HasOne<RentalFeedback, $this>
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(RentalFeedback::class, 'boat_booking_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paymentVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function passengerNoShowAdminAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_no_show_admin_acknowledged_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function balanceRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'balance_recorded_by');
    }

    /**
     * @return HasMany<BoatBookingActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(BoatBookingActivity::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<OperatorNoShowReport, $this>
     */
    public function operatorNoShowReports(): HasMany
    {
        return $this->hasMany(OperatorNoShowReport::class)->orderByDesc('id');
    }

    /**
     * @return HasOne<PassengerNoShowReport, $this>
     */
    public function passengerNoShowReport(): HasOne
    {
        return $this->hasOne(PassengerNoShowReport::class)->withoutGlobalScope('organization');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operatorAvailabilityConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_availability_confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tripConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trip_confirmed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function meetingPointArrivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_meeting_point_arrived_by_user_id');
    }

    /**
     * @return HasMany<BoatBookingReplacement, $this>
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(BoatBookingReplacement::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<WeatherSuspension, $this>
     */
    public function weatherSuspension(): BelongsTo
    {
        return $this->belongsTo(WeatherSuspension::class);
    }

    /**
     * @return HasMany<BookingReschedule, $this>
     */
    public function reschedules(): HasMany
    {
        return $this->hasMany(BookingReschedule::class)->orderByDesc('id');
    }

    /**
     * @return HasOne<RentalCommission, $this>
     */
    public function commission(): HasOne
    {
        return $this->hasOne(RentalCommission::class);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::Pending,
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
            BoatBookingStatus::WeatherHold,
            BoatBookingStatus::ReschedulePending,
        ], true);
    }

    /**
     * Once confirmed, only the operator (app) or weather/no-show flows may end the rental.
     */
    public function canBeCancelledByAdmin(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::Pending,
            BoatBookingStatus::AwaitingPayment,
        ], true);
    }

    public function canBeApprovedByAdmin(): bool
    {
        return $this->status === BoatBookingStatus::Pending && $this->user_id !== null;
    }

    public function hasRecordedApproval(): bool
    {
        $items = $this->relationLoaded('activities')
            ? $this->activities
            : $this->activities()->get();

        return $items->contains(
            fn (BoatBookingActivity $activity) => in_array($activity->event, ['operator_accepted', 'admin_approved'], true),
        );
    }

    public function shouldHaveApprovalRecord(): bool
    {
        if ($this->user_id === null) {
            return false;
        }

        return in_array($this->status, [
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
            BoatBookingStatus::Completed,
            BoatBookingStatus::WeatherHold,
            BoatBookingStatus::ReschedulePending,
            BoatBookingStatus::PassengerNoShow,
        ], true);
    }

    public function backfillMissingApprovalActivity(): void
    {
        if (! $this->shouldHaveApprovalRecord() || $this->hasRecordedApproval()) {
            return;
        }

        $this->loadMissing('boat.operator.user');
        $operatorUser = $this->boat?->operator?->user;
        $name = $this->boat?->operator?->full_name
            ?? $operatorUser?->name
            ?? 'Boat operator';
        $activity = $this->recordActivity(
            'operator_accepted',
            $operatorUser instanceof User ? $operatorUser : null,
            $name,
        );
        $occurred = $this->payment_submitted_at ?? $this->updated_at;
        if ($occurred) {
            $activity->forceFill(['created_at' => $occurred])->saveQuietly();
        }
        $this->unsetRelation('activities');
    }

    public function requiresDownPayment(): bool
    {
        return $this->down_payment_amount !== null
            && (float) $this->down_payment_amount > 0;
    }

    public function paymentStatus(): BookingPaymentStatus
    {
        $status = $this->payment_status instanceof BookingPaymentStatus
            ? $this->payment_status
            : (BookingPaymentStatus::tryFrom((string) $this->payment_status) ?? BookingPaymentStatus::Unpaid);

        if (
            $status === BookingPaymentStatus::Unpaid
            && (
                filled($this->gcash_reference)
                || filled($this->payment_receipt_path)
                || $this->payment_submitted_at !== null
            )
        ) {
            return BookingPaymentStatus::ForVerification;
        }

        return $status;
    }

    public function hasPaymentSubmission(): bool
    {
        return in_array($this->paymentStatus(), [
            BookingPaymentStatus::ForVerification,
            BookingPaymentStatus::Verified,
            BookingPaymentStatus::Rejected,
        ], true)
            || filled($this->gcash_reference)
            || filled($this->payment_receipt_path)
            || $this->payment_submitted_at !== null;
    }

    public function canSubmitPaymentProof(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::WeatherHold,
            BoatBookingStatus::ReschedulePending,
        ], true)
            && $this->requiresDownPayment()
            && in_array($this->paymentStatus(), [
                BookingPaymentStatus::Unpaid,
                BookingPaymentStatus::Rejected,
            ], true);
    }

    public function canVerifyPayment(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::WeatherHold,
            BoatBookingStatus::ReschedulePending,
        ], true)
            && $this->paymentStatus() === BookingPaymentStatus::ForVerification;
    }

    public function requiresAdminAttention(): bool
    {
        if (in_array($this->status, [
            BoatBookingStatus::Pending,
            BoatBookingStatus::ReschedulePending,
        ], true)) {
            return true;
        }

        return $this->canVerifyPayment();
    }

    /**
     * @param  Builder<BoatBooking>  $query
     * @return Builder<BoatBooking>
     */
    public function scopeRequiringAdminAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer
                ->whereIn('status', [
                    BoatBookingStatus::Pending,
                    BoatBookingStatus::ReschedulePending,
                ])
                ->orWhere(function (Builder $payment): void {
                    $payment
                        ->whereIn('status', [
                            BoatBookingStatus::AwaitingPayment,
                            BoatBookingStatus::WeatherHold,
                            BoatBookingStatus::ReschedulePending,
                        ])
                        ->where(function (Builder $proof): void {
                            $proof
                                ->where('payment_status', BookingPaymentStatus::ForVerification)
                                ->orWhere(function (Builder $unpaid): void {
                                    $unpaid
                                        ->where('payment_status', BookingPaymentStatus::Unpaid)
                                        ->where(function (Builder $submitted): void {
                                            $submitted
                                                ->whereNotNull('gcash_reference')
                                                ->orWhereNotNull('payment_receipt_path')
                                                ->orWhereNotNull('payment_submitted_at');
                                        });
                                });
                        });
                });
        });
    }

    public function gcashPayee(): GcashPayee
    {
        if ($this->gcash_payee instanceof GcashPayee) {
            return $this->gcash_payee;
        }

        return GcashPayee::tryFrom((string) $this->gcash_payee) ?? $this->resolveGcashPayee();
    }

    public function paysBarangayGcash(): bool
    {
        return $this->gcashPayee() === GcashPayee::Barangay;
    }

    public function paysOperatorGcash(): bool
    {
        return $this->gcashPayee() === GcashPayee::Operator;
    }

    public function isExclusiveRental(): bool
    {
        return $this->rental_price !== null && (float) $this->rental_price > 0;
    }

    public function resolveGcashPayee(): GcashPayee
    {
        return GcashPayee::Barangay;
    }

    public function snapshotGcashPayee(bool $force = false): GcashPayee
    {
        if (! $force && $this->gcash_payee instanceof GcashPayee && $this->hasPaymentSubmission()) {
            return $this->gcash_payee;
        }

        $payee = $this->resolveGcashPayee();

        if ($this->gcash_payee !== $payee) {
            $this->gcash_payee = $payee;
        }

        return $payee;
    }

    public function snapshotPaymentRecipient(): GcashPayee
    {
        $this->loadMissing(['organization', 'boat.operator.gcashAccounts']);
        $payee = $this->snapshotGcashPayee(true);

        $this->gcash_recipient_account_name = $this->organization?->gcash_account_name;
        $this->gcash_recipient_mobile = null;

        return $payee;
    }

    public static function gcashReferenceAlreadyUsed(
        int $organizationId,
        string $reference,
        ?int $exceptBookingId = null,
    ): bool {
        $normalized = strtoupper(trim($reference));

        if ($normalized === '') {
            return false;
        }

        return static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->whereNotIn('status', [
                BoatBookingStatus::Cancelled,
                BoatBookingStatus::Rejected,
            ])
            ->where(function ($query) use ($normalized) {
                $query->where(function ($inner) use ($normalized) {
                    $inner->whereRaw('UPPER(TRIM(gcash_reference)) = ?', [$normalized])
                        ->whereIn('payment_status', [
                            BookingPaymentStatus::ForVerification,
                            BookingPaymentStatus::Verified,
                        ]);
                })->orWhere(function ($inner) use ($normalized) {
                    $inner->whereRaw('UPPER(TRIM(balance_reference)) = ?', [$normalized])
                        ->where('balance_status', BookingBalanceStatus::Paid);
                });
            })
            ->exists();
    }

    public function hasOutstandingBalance(): bool
    {
        return $this->remaining_balance !== null
            && (float) $this->remaining_balance > 0;
    }

    public function balanceStatus(): BookingBalanceStatus
    {
        if (! $this->hasOutstandingBalance()) {
            return BookingBalanceStatus::Paid;
        }

        if ($this->balance_status instanceof BookingBalanceStatus) {
            return $this->balance_status;
        }

        return BookingBalanceStatus::tryFrom((string) $this->balance_status)
            ?? BookingBalanceStatus::Unpaid;
    }

    public function balanceIsSettled(): bool
    {
        return $this->balanceStatus() === BookingBalanceStatus::Paid;
    }

    public function canRecordBalancePayment(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
        ], true)
            && $this->hasOutstandingBalance()
            && ! $this->balanceIsSettled();
    }

    public function isFullyPaid(): bool
    {
        if (in_array($this->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
        ], true)) {
            return false;
        }

        if ($this->requiresDownPayment() && $this->paymentStatus() !== BookingPaymentStatus::Verified) {
            return false;
        }

        return $this->balanceIsSettled();
    }

    public function overallPaymentStatus(): string
    {
        if ($this->status === BoatBookingStatus::PassengerNoShow) {
            return 'non_refundable';
        }

        if (in_array($this->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
        ], true)) {
            if ($this->operatorNoShowRefundCompleted()) {
                return 'refunded';
            }

            return 'cancelled';
        }

        if ($this->isFullyPaid()) {
            return 'fully_paid';
        }

        if ($this->paymentStatus() === BookingPaymentStatus::Verified) {
            return 'partially_paid';
        }

        if ($this->paymentStatus() === BookingPaymentStatus::ForVerification) {
            return 'awaiting_verification';
        }

        if (\App\Support\OnlinePaymentProvider::isEnabled()) {
            $checkout = app(\App\Services\HostedRentalCheckoutService::class);

            if ($checkout->requiresAnyReconciliation($this)) {
                return 'requires_reconciliation';
            }

            if ($checkout->hasAnyBlockingPendingCheckout($this)) {
                return 'online_checkout_pending';
            }
        }

        return 'awaiting_payment';
    }

    public function overallPaymentStatusLabel(): string
    {
        return match ($this->overallPaymentStatus()) {
            'fully_paid' => 'Fully Paid',
            'partially_paid' => 'Partially Paid',
            'non_refundable' => 'Non-refundable',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            'online_checkout_pending' => 'Online Checkout Pending',
            'requires_reconciliation' => 'Requires Payment Reconciliation',
            'awaiting_payment' => 'Awaiting Payment',
            default => 'Awaiting Verification',
        };
    }

    public function operatorNoShowRefundCompleted(): bool
    {
        $reports = $this->relationLoaded('operatorNoShowReports')
            ? $this->operatorNoShowReports
            : null;

        if ($reports !== null) {
            return $reports->contains(
                fn (OperatorNoShowReport $report) => $report->refund_status === \App\Enums\OperatorNoShowRefundStatus::Refunded,
            );
        }

        return $this->operatorNoShowReports()
            ->where('refund_status', \App\Enums\OperatorNoShowRefundStatus::Refunded)
            ->exists();
    }

    /**
     * An unrecorded remaining balance does not block completion; it is settled as paid during the trip.
     */
    public function canCompleteTrip(): bool
    {
        return in_array($this->status, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
        ], true)
            && ! $this->weather_review_required
            && (! $this->requiresDownPayment() || $this->paymentStatus() === BookingPaymentStatus::Verified);
    }

    public function canRequestReschedule(): bool
    {
        return $this->status->isWeatherPaused();
    }

    public function pendingRequestedBookingDate(): ?Carbon
    {
        if ($this->requested_booking_date) {
            return $this->requested_booking_date;
        }

        $open = $this->relationLoaded('reschedules')
            ? $this->reschedules->firstWhere('status', 'requested')
            : $this->reschedules()->where('status', 'requested')->latest('id')->first();

        return $open?->to_date;
    }

    public function canReviewReschedule(): bool
    {
        if ($this->pendingRequestedBookingDate() === null) {
            return false;
        }

        return $this->status === BoatBookingStatus::ReschedulePending
            || $this->hasOpenWeatherRescheduleRequest();
    }

    public function hasOpenWeatherRescheduleRequest(): bool
    {
        if ($this->status !== BoatBookingStatus::WeatherHold) {
            return false;
        }

        if ($this->reschedule_status === 'pending_approval') {
            return true;
        }

        return $this->relationLoaded('reschedules')
            ? $this->reschedules->contains('status', 'requested')
            : $this->reschedules()->where('status', 'requested')->exists();
    }

    public function isWeatherRescheduleConfirmed(): bool
    {
        if (! in_array($this->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
            return false;
        }

        if ($this->reschedule_status !== 'approved') {
            return false;
        }

        $original = $this->original_booking_date ?? $this->booking_date;

        return $original !== null && ! $this->booking_date->equalTo($original);
    }

    public function isOperatorRescheduleConfirmed(): bool
    {
        if (! in_array($this->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
            return false;
        }

        $reports = $this->relationLoaded('operatorNoShowReports')
            ? $this->operatorNoShowReports
            : $this->operatorNoShowReports()->get();

        return $reports->contains(
            fn ($report) => $report->reschedule_status === OperatorNoShowRescheduleStatus::Approved,
        );
    }

    public function isRescheduleConfirmed(): bool
    {
        return $this->isWeatherRescheduleConfirmed() || $this->isOperatorRescheduleConfirmed();
    }

    public function adminStatusLabel(): string
    {
        if ($this->isRescheduleConfirmed()) {
            return 'Reschedule Confirmed';
        }

        return $this->status->label();
    }

    public function originalDateLabel(): ?string
    {
        return ($this->original_booking_date ?? $this->booking_date)?->format('M d, Y');
    }

    public function recordActivity(
        string $event,
        ?User $actor = null,
        ?string $actorName = null,
        ?array $metadata = null,
    ): BoatBookingActivity {
        $actor ??= auth()->user();

        return $this->activities()->create([
            'event' => $event,
            'title' => self::activityTitle($event),
            'actor_id' => $actor?->id,
            'actor_name' => $actorName ?? $actor?->name,
            'metadata' => $metadata,
        ]);
    }

    public static function activityTitle(string $event): string
    {
        return match ($event) {
            'submitted' => 'Rental Request Submitted',
            'walk_in_cash_created' => 'Walk-in Booking Created',
            'walk_in_gcash_created' => 'Walk-in Booking Created',
            'operator_accepted' => 'Boat Rental Request Approved',
            'admin_approved' => 'Boat Rental Request Approved',
            'payment_submitted' => 'Payment Proof Submitted',
            'payment_verified' => 'Down Payment Verified',
            'paymongo_payment_verified' => 'Down Payment Verified',
            'payment_rejected' => 'Payment Proof Rejected',
            'balance_paid' => 'Remaining Balance Recorded',
            'balance_paid_on_trip' => 'Remaining Balance Paid During Trip',
            'completed' => 'Rental Completed',
            'passenger_no_show' => 'Passenger Did Not Show',
            'cancelled' => 'Rental Cancelled',
            'weather_hold' => 'Weather Hold – Rescheduling Required',
            'reschedule_requested' => 'Reschedule Request Submitted',
            'reschedule_approved' => 'Reschedule Approved',
            'reschedule_rejected' => 'Reschedule Rejected',
            'operator_no_show_reported' => 'Operator No-Show Reported',
            'operator_no_show_verified' => 'Operator No-Show Verified',
            'operator_no_show_dismissed' => 'No-Show Report Dismissed',
            'operator_no_show_reassigned' => 'Boat Reassigned After No-Show',
            'operator_no_show_reschedule_offered' => 'Reschedule Offered (Operator No-Show)',
            'operator_no_show_refund_approved' => 'Down Payment Refund Approved',
            'operator_no_show_refund_completed' => 'Down Payment Refund Completed',
            'operator_no_show_passenger_chose_refund' => 'Passenger Chose Full Refund',
            'operator_no_show_passenger_chose_reschedule' => 'Passenger Chose Reschedule',
            'operator_no_show_passenger_chose_replacement' => 'Passenger Chose Replacement Boat',
            'operator_no_show_reschedule_approved' => 'Reschedule Confirmed (Operator No-Show)',
            'operator_no_show_reschedule_rejected' => 'Reschedule Declined (Operator No-Show)',
            'operator_no_show_violation_recorded' => 'Operator Violation Recorded',
            'operator_availability_confirmed' => 'Operator Confirmed Availability',
            'trip_confirmation_requested' => 'Confirmation Request Sent',
            'trip_confirmed_operator' => 'Operator Confirmed Trip',
            'trip_confirmed_admin_phone' => 'Admin Phone Confirmation — Trip Confirmed',
            'trip_cannot_operate_operator' => 'Operator Cannot Operate',
            'trip_cannot_operate_admin_phone' => 'Admin Phone Confirmation — Cannot Operate',
            'trip_confirmation_conflict' => 'Trip Confirmation Conflict — Admin Review',
            'replacement_assignment_offered' => 'Replacement Boat Offered',
            'replacement_operator_accepted' => 'Replacement Operator Accepted',
            'replacement_operator_declined' => 'Replacement Operator Declined',
            'replacement_admin_approved' => 'Replacement Approved by Admin',
            'replacement_not_available' => 'No Replacement Available',
            'operator_meeting_point_arrived' => 'Operator Arrived at Meeting Point',
            'passenger_checked_in' => 'Passenger Checked In',
            'passenger_no_show_recorded' => 'Passenger No-Show Recorded',
            'passenger_attendance_corrected' => 'Passenger Attendance Corrected',
            default => ucfirst(str_replace('_', ' ', $event)),
        };
    }

    /**
     * @return list<array{id: int|string, event: string, title: string, actor_name: string|null, occurred_at: string|null, occurred_at_label: string|null}>
     */
    public function activityTimeline(): array
    {
        $items = $this->relationLoaded('activities')
            ? $this->activities
            : $this->activities()->get();

        $records = $items->map(fn (BoatBookingActivity $activity) => $activity->toRecord())->values()->all();

        $hasSubmitted = collect($records)->contains(fn (array $item) => $item['event'] === 'submitted');
        $hasWalkInCreated = collect($records)->contains(
            fn (array $item) => in_array($item['event'], ['walk_in_cash_created', 'walk_in_gcash_created'], true),
        );

        if (! $hasSubmitted && ! $hasWalkInCreated) {
            array_unshift($records, [
                'id' => 'submitted-'.$this->id,
                'event' => 'submitted',
                'title' => self::activityTitle('submitted'),
                'actor_name' => $this->customer_name,
                'occurred_at' => $this->created_at?->toIso8601String(),
                'occurred_at_label' => $this->created_at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            ]);
        }

        $hasApproval = collect($records)->contains(
            fn (array $item) => in_array($item['event'], ['operator_accepted', 'admin_approved'], true),
        );

        if (! $hasApproval && $this->shouldHaveApprovalRecord()) {
            $this->loadMissing('boat.operator.user');
            $occurred = $this->payment_submitted_at ?? $this->updated_at;
            $records[] = [
                'id' => 'approved-'.$this->id,
                'event' => 'operator_accepted',
                'title' => self::activityTitle('operator_accepted'),
                'actor_name' => $this->boat?->operator?->full_name
                    ?? $this->boat?->operator?->user?->name
                    ?? 'Boat operator',
                'occurred_at' => $occurred?->toIso8601String(),
                'occurred_at_label' => $occurred?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            ];
        }

        usort($records, function (array $left, array $right): int {
            return strcmp((string) ($left['occurred_at'] ?? ''), (string) ($right['occurred_at'] ?? ''));
        });

        return $records;
    }
}
