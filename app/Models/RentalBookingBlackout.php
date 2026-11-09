<?php

namespace App\Models;

use App\Enums\RentalBlackoutReason;
use App\Enums\RentalBlackoutStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property RentalBlackoutReason $reason
 * @property string|null $remarks
 * @property bool $auto_resume
 * @property RentalBlackoutStatus $status
 * @property int $created_by
 * @property int|null $updated_by
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_notes
 * @property Carbon|null $completed_at
 * @property int|null $announcement_id
 */
#[Fillable([
    'organization_id',
    'code',
    'start_date',
    'end_date',
    'reason',
    'remarks',
    'auto_resume',
    'status',
    'created_by',
    'updated_by',
    'cancelled_by',
    'cancelled_at',
    'cancel_notes',
    'completed_at',
    'announcement_id',
])]
class RentalBookingBlackout extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reason' => RentalBlackoutReason::class,
            'auto_resume' => 'boolean',
            'status' => RentalBlackoutStatus::class,
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function nextCode(): string
    {
        $year = (int) now()->year;
        $prefix = sprintf('RB-%d-', $year);

        $last = static::query()
            ->withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->reduce(function (int $max, mixed $code) use ($prefix): int {
                $suffix = substr((string) $code, strlen($prefix));

                return ctype_digit($suffix) ? max($max, (int) $suffix) : $max;
            }, 0);

        return sprintf('RB-%d-%05d', $year, $last + 1);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RentalBlackoutStatus::Scheduled,
            RentalBlackoutStatus::Active,
        ]);
    }

    public function covers(Carbon|string $date): bool
    {
        $onDate = Carbon::parse($date)->toDateString();

        return $onDate >= $this->start_date->toDateString()
            && $onDate <= $this->end_date->toDateString();
    }

    public function periodLabel(): string
    {
        $start = $this->start_date->format('M d, Y');
        $end = $this->end_date->format('M d, Y');

        return $start === $end ? $start : $start.' – '.$end;
    }

    public function passengerMessage(): string
    {
        return 'Exclusive boat rental bookings are temporarily unavailable on this date due to '
            .$this->reason->phrase()
            .'. All boats will prioritize regular passenger trips during this period. ('
            .$this->periodLabel()
            .')';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminRecord(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'period_label' => $this->periodLabel(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'remarks' => $this->remarks,
            'auto_resume' => $this->auto_resume,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_at_label' => $this->created_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'created_by_name' => $this->creator?->name,
            'updated_by_name' => $this->editor?->name,
            'can_edit' => in_array($this->status, [RentalBlackoutStatus::Scheduled, RentalBlackoutStatus::Active], true),
            'can_cancel' => in_array($this->status, [RentalBlackoutStatus::Scheduled, RentalBlackoutStatus::Active], true),
        ];
    }
}
