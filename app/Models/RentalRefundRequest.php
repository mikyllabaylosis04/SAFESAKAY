<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalRefundRequest extends Model
{
    /** Passenger cancelled a fully paid rental and asked for a refund; the barangay admin reviews it. */
    public const TYPE_PASSENGER_CANCELLATION = 'passenger_cancellation';

    /** The boat operator cancelled (cannot operate) a rental the passenger already paid for. */
    public const TYPE_OPERATOR_CANCELLED = 'operator_cancelled';

    /** The barangay admin cancelled a rental the passenger already paid for. */
    public const TYPE_ADMIN_CANCELLED = 'admin_cancelled';

    /** Verified operator/boat no-show: the service was not delivered, so the paid amount is refunded. */
    public const TYPE_OPERATOR_NO_SHOW = 'operator_no_show';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED = 'rejected';

    /** Operator/admin cancellation: the passenger still has to pick a full refund or a new date. */
    public const STATUS_AWAITING_CHOICE = 'awaiting_choice';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUS_REFUNDING = 'refunding';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_FAILED = 'failed';

    public const REASONS = [
        'emergency' => 'Family or Personal Emergency',
        'medical' => 'Illness or Medical Reason',
        'weather_concern' => 'Weather or Safety Concern',
        'schedule_conflict' => 'Schedule Conflict',
        'change_of_plans' => 'Change of Plans',
        'operator_unavailable' => 'Operator Unavailability',
        'boat_issue' => 'Boat or Mechanical Issue',
    ];

    protected $fillable = [
        'organization_id',
        'boat_booking_id',
        'requested_by_user_id',
        'reference',
        'type',
        'status',
        'reason',
        'reason_category',
        'details',
        'requested_amount',
        'amount_paid',
        'refund_amount',
        'admin_notes',
        'rejection_reason',
        'reviewed_by_user_id',
        'reviewed_at',
        'failure_message',
        'manual_reference',
        'provider_refund_id',
        'refunded_at',
    ];

    protected static function booted(): void
    {
        static::created(function (RentalRefundRequest $refund): void {
            if (! filled($refund->reference)) {
                $refund->forceFill([
                    'reference' => 'RF-'.now('Asia/Manila')->format('Y').'-'.str_pad((string) $refund->id, 5, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    public function displayReference(): string
    {
        if (filled($this->reference)) {
            return (string) $this->reference;
        }

        $year = ($this->created_at ?? now())->timezone('Asia/Manila')->format('Y');

        return 'RF-'.$year.'-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'refund_amount' => 'integer',
            'requested_amount' => 'integer',
            'amount_paid' => 'integer',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BoatBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return HasMany<RentalRefundEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RentalRefundEvent::class, 'rental_refund_request_id')->orderBy('id');
    }

    public function recordEvent(string $event, string $title, ?string $detail = null, ?User $actor = null, ?string $actorName = null): void
    {
        $this->events()->create([
            'event' => $event,
            'title' => $title,
            'detail' => filled($detail) ? mb_substr((string) $detail, 0, 1000) : null,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actorName ?? $actor?->name,
        ]);
    }

    /** Operator or admin cancelled: the passenger is protected (full refund or reschedule). */
    public function isNotPassengersFault(): bool
    {
        return in_array($this->type, [self::TYPE_OPERATOR_CANCELLED, self::TYPE_ADMIN_CANCELLED, self::TYPE_OPERATOR_NO_SHOW], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_REFUNDED, self::STATUS_REJECTED, self::STATUS_RESCHEDULED], true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PASSENGER_CANCELLATION => 'Cancelled by passenger',
            self::TYPE_OPERATOR_CANCELLED => 'Operator cannot operate',
            self::TYPE_ADMIN_CANCELLED => 'Cancelled by barangay admin',
            self::TYPE_OPERATOR_NO_SHOW => 'Boat or operator no-show',
            default => 'Refund',
        };
    }

    public function reasonLabel(): string
    {
        if ($this->reason_category && isset(self::REASONS[$this->reason_category])) {
            return self::REASONS[$this->reason_category];
        }

        return filled($this->reason) ? (string) $this->reason : $this->typeLabel();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_REVIEW => 'Pending Review',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_AWAITING_CHOICE => 'Waiting for passenger: refund or reschedule',
            self::STATUS_RESCHEDULED => 'Passenger chose to reschedule',
            self::STATUS_REFUNDING => 'Processing',
            self::STATUS_REFUNDED => 'Refunded',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_REVIEW, self::STATUS_AWAITING_CHOICE => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
            self::STATUS_REFUNDING => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
            self::STATUS_REFUNDED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
            self::STATUS_FAILED, self::STATUS_REJECTED => 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
            default => 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
        };
    }

    public function refundAmountLabel(): ?string
    {
        return $this->refund_amount !== null ? '₱'.number_format($this->refund_amount / 100, 2) : null;
    }
}
