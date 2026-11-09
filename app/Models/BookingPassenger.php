<?php

namespace App\Models;

use App\Enums\PassengerAttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int $boat_booking_id
 * @property string $full_name
 * @property int $age
 * @property string $gender
 * @property string|null $contact_number
 * @property int $sort_order
 */
#[Fillable([
    'organization_id',
    'boat_booking_id',
    'full_name',
    'age',
    'gender',
    'contact_number',
    'sort_order',
    'attendance_status',
    'checked_in_at',
    'no_show_recorded_at',
    'no_show_recorded_by_user_id',
    'no_show_remarks',
    'attendance_admin_note',
    'attendance_corrected_by_user_id',
    'attendance_corrected_at',
])]
class BookingPassenger extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'sort_order' => 'integer',
            'attendance_status' => PassengerAttendanceStatus::class,
            'checked_in_at' => 'datetime',
            'no_show_recorded_at' => 'datetime',
            'attendance_corrected_at' => 'datetime',
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
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'age' => $this->age,
            'gender' => $this->gender,
            'contact_number' => $this->contact_number,
            'attendance_status' => ($this->attendance_status ?? PassengerAttendanceStatus::NotYetArrived)->value,
            'attendance_status_label' => ($this->attendance_status ?? PassengerAttendanceStatus::NotYetArrived)->label(),
            'checked_in_at_label' => $this->checked_in_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
        ];
    }
}
