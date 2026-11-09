<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $boat_booking_id
 * @property int|null $reported_by_user_id
 * @property int|null $passenger_user_id
 * @property int|null $operator_id
 * @property int|null $boat_id
 * @property Carbon|null $scheduled_departure_at
 * @property Carbon $reported_at
 * @property string|null $operator_remarks
 * @property string|null $evidence_photo_path
 * @property bool $declaration_confirmed
 * @property string $cancellation_reason
 * @property string $amount_paid
 * @property string $retained_amount
 * @property bool $refund_eligible
 * @property string $status
 * @property string|null $dispute_reason
 * @property Carbon|null $disputed_at
 * @property string|null $resolution_notes
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 */
class PassengerNoShowReport extends Model
{
    use BelongsToOrganization;

    public const STATUS_CONFIRMED = 'confirmed_passenger_no_show';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_UPHELD = 'upheld';

    public const STATUS_REVERSED = 'reversed';

    public const CANCELLATION_REASON = 'passenger_no_show';

    protected $fillable = [
        'organization_id',
        'boat_booking_id',
        'reported_by_user_id',
        'passenger_user_id',
        'operator_id',
        'boat_id',
        'scheduled_departure_at',
        'reported_at',
        'operator_remarks',
        'evidence_photo_path',
        'declaration_confirmed',
        'cancellation_reason',
        'amount_paid',
        'retained_amount',
        'refund_eligible',
        'status',
        'dispute_reason',
        'disputed_at',
        'resolution_notes',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_departure_at' => 'datetime',
            'reported_at' => 'datetime',
            'disputed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'declaration_confirmed' => 'boolean',
            'refund_eligible' => 'boolean',
            'amount_paid' => 'decimal:2',
            'retained_amount' => 'decimal:2',
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
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DISPUTED => 'Disputed by passenger',
            self::STATUS_UPHELD => 'No-show upheld',
            self::STATUS_REVERSED => 'No-show reversed',
            default => 'Confirmed passenger no-show',
        };
    }

    public function disputeStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DISPUTED => 'Under review',
            self::STATUS_UPHELD => 'Reviewed · no-show upheld',
            self::STATUS_REVERSED => 'Reviewed · no-show reversed',
            default => $this->disputeWindowExpired()
                ? 'Dispute window ended'
                : 'You have 1 day to dispute',
        };
    }

    public function disputeDeadlineAt(): ?Carbon
    {
        return $this->reported_at
            ? $this->reported_at->copy()->timezone('Asia/Manila')->addDay()
            : null;
    }

    public function disputeWindowExpired(): bool
    {
        $deadline = $this->disputeDeadlineAt();

        return $this->status === self::STATUS_CONFIRMED
            && $deadline !== null
            && now('Asia/Manila')->gte($deadline);
    }

    public function refundStatusLabel(): string
    {
        return $this->status === self::STATUS_REVERSED
            ? 'Refund or new date offered'
            : 'Not eligible under no-show policy';
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $this->loadMissing(['reporter', 'resolver', 'boat', 'operator']);
        $peso = fn ($value) => '₱'.number_format((float) $value, 2);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'cancellation_reason_label' => 'Passenger No-Show',
            'reported_by_label' => 'Boat Operator',
            'reported_by_name' => $this->reporter?->name,
            'operator_name' => $this->operator?->full_name,
            'boat_name' => $this->boat?->name,
            'scheduled_departure_label' => $this->scheduled_departure_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'reported_at_label' => $this->reported_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'operator_remarks' => $this->operator_remarks,
            'evidence_photo_url' => filled($this->evidence_photo_path)
                ? '/storage/'.ltrim($this->evidence_photo_path, '/')
                : null,
            'declaration_confirmed' => $this->declaration_confirmed,
            'amount_paid_label' => $peso($this->amount_paid),
            'retained_amount_label' => $peso($this->retained_amount),
            'refund_status_label' => $this->refundStatusLabel(),
            'dispute_status' => $this->status === self::STATUS_CONFIRMED ? 'none' : $this->status,
            'dispute_status_label' => $this->disputeStatusLabel(),
            'dispute_deadline_at' => $this->disputeDeadlineAt()?->toIso8601String(),
            'dispute_deadline_label' => $this->disputeDeadlineAt()?->format('M d, Y · g:i A'),
            'dispute_window_days' => 1,
            'dispute_reason' => $this->dispute_reason,
            'disputed_at_label' => $this->disputed_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'resolution_notes' => $this->resolution_notes,
            'resolved_by_name' => $this->resolver?->name,
            'resolved_at_label' => $this->resolved_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
        ];
    }
}
