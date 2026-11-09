<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'operator_id',
    'operator_no_show_report_id',
    'boat_booking_id',
    'strike_number',
    'incident_date',
    'booking_code',
    'reason',
    'recorded_by_user_id',
])]
class OperatorNoShowViolation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'strike_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<OperatorNoShowReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(OperatorNoShowReport::class, 'operator_no_show_report_id');
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminRecord(): array
    {
        $this->loadMissing(['operator.boat', 'booking', 'recorder']);

        return [
            'id' => $this->id,
            'strike_number' => $this->strike_number,
            'strike_label' => $this->strike_number.'/3',
            'incident_date_label' => $this->incident_date->format('M d, Y'),
            'booking_code' => $this->booking_code,
            'booking_id' => $this->boat_booking_id,
            'reason' => $this->reason,
            'operator_name' => $this->operator?->full_name,
            'boat_name' => $this->operator?->boat?->name,
            'recorded_at_label' => $this->created_at?->format('M d, Y · g:i A'),
            'recorded_by_name' => $this->recorder?->name,
            'operator_exclusive_restricted' => $this->operator?->isExclusiveRentalRestricted() ?? false,
        ];
    }
}
