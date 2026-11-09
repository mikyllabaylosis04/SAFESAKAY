<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $boat_booking_id
 * @property int|null $weather_suspension_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string $status
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property string|null $rejection_reason
 * @property bool $payment_carried_forward
 * @property string|null $down_payment_amount
 * @property string|null $payment_status
 * @property Carbon|null $reviewed_at
 */
#[Fillable([
    'boat_booking_id',
    'weather_suspension_id',
    'from_date',
    'to_date',
    'status',
    'requested_by',
    'reviewed_by',
    'rejection_reason',
    'payment_carried_forward',
    'down_payment_amount',
    'payment_status',
    'reviewed_at',
])]
class BookingReschedule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'payment_carried_forward' => 'boolean',
            'down_payment_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
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
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'from_date' => $this->from_date->toDateString(),
            'from_date_label' => $this->from_date->format('M d, Y'),
            'to_date' => $this->to_date->toDateString(),
            'to_date_label' => $this->to_date->format('M d, Y'),
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => 'Requested',
            },
            'requested_by_name' => $this->requester?->name,
            'reviewed_by_name' => $this->reviewer?->name,
            'rejection_reason' => $this->rejection_reason,
            'payment_carried_forward' => $this->payment_carried_forward,
            'down_payment_amount_label' => $this->down_payment_amount !== null
                ? '₱'.number_format((float) $this->down_payment_amount, 2)
                : null,
            'payment_status' => $this->payment_status,
            'created_at_label' => $this->created_at?->format('M d, Y · g:i A'),
            'reviewed_at_label' => $this->reviewed_at?->format('M d, Y · g:i A'),
        ];
    }
}
