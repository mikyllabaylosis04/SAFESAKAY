<?php

namespace App\Models;

use App\Enums\BoatBookingReplacementStatus;
use App\Enums\TripConfirmationMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'boat_booking_id',
    'original_boat_id',
    'original_operator_user_id',
    'replacement_boat_id',
    'replacement_operator_user_id',
    'status',
    'reason',
    'initiated_confirmation_method',
    'initiated_by_user_id',
    'initiated_at',
    'operator_responded_at',
    'operator_decline_reason',
    'admin_approved_by_user_id',
    'admin_approved_at',
    'admin_notes',
])]
class BoatBookingReplacement extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BoatBookingReplacementStatus::class,
            'initiated_confirmation_method' => TripConfirmationMethod::class,
            'initiated_at' => 'datetime',
            'operator_responded_at' => 'datetime',
            'admin_approved_at' => 'datetime',
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
     * @return BelongsTo<Boat, $this>
     */
    public function originalBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'original_boat_id');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function replacementBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'replacement_boat_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            BoatBookingReplacementStatus::PendingOperator,
            BoatBookingReplacementStatus::PendingAdminApproval,
            BoatBookingReplacementStatus::Declined,
        ], true);
    }
}
