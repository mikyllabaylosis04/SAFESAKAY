<?php

namespace App\Models;

use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int $boat_id
 * @property string $route
 * @property string|null $direction
 * @property int $rotation_order
 * @property int|null $queue_order
 * @property int $cycle_number
 * @property RegularTripScheduleMode $schedule_mode
 * @property Carbon $trip_date
 * @property Carbon $departure_time
 * @property int $passenger_count
 * @property RegularTripStatus $status
 * @property Carbon|null $departed_at
 * @property Carbon|null $boarding_started_at
 * @property int $boarding_wait_minutes
 * @property Carbon|null $boarding_wait_notified_at
 * @property Carbon|null $arrived_at
 * @property Carbon|null $completed_at
 * @property int|null $return_of_id
 * @property string|null $remarks
 * @property bool $is_auto_generated
 */
#[Fillable([
    'organization_id',
    'boat_id',
    'return_of_id',
    'route',
    'direction',
    'rotation_order',
    'queue_order',
    'cycle_number',
    'schedule_mode',
    'trip_date',
    'departure_time',
    'passenger_count',
    'status',
    'departed_at',
    'boarding_started_at',
    'boarding_wait_minutes',
    'boarding_wait_notified_at',
    'arrived_at',
    'completed_at',
    'remarks',
    'weather_suspension_id',
    'weather_review_required',
    'is_auto_generated',
])]
class RegularTrip extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'departure_time' => 'datetime:H:i',
            'rotation_order' => 'integer',
            'queue_order' => 'integer',
            'cycle_number' => 'integer',
            'schedule_mode' => RegularTripScheduleMode::class,
            'passenger_count' => 'integer',
            'status' => RegularTripStatus::class,
            'departed_at' => 'datetime',
            'boarding_started_at' => 'datetime',
            'boarding_wait_minutes' => 'integer',
            'boarding_wait_notified_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_auto_generated' => 'boolean',
            'weather_review_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * @return BelongsTo<RegularTrip, $this>
     */
    public function goingTrip(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_id');
    }

    /**
     * @return HasMany<RegularTrip, $this>
     */
    public function returnTrips(): HasMany
    {
        return $this->hasMany(self::class, 'return_of_id');
    }

    /**
     * @return HasMany<TripPassenger, $this>
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(TripPassenger::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return Collection<int, TripPassenger>
     */
    public function manifestPassengers(): Collection
    {
        $this->loadMissing(['boat', 'passengers']);

        $capacity = max(0, (int) $this->boat?->capacity);

        return $this->passengers
            ->take($capacity)
            ->values();
    }

    public function manifestCount(): int
    {
        return $this->manifestPassengers()->count();
    }

    public function routeLabel(): string
    {
        return $this->direction ?: $this->route;
    }

    public function headingLabel(): string
    {
        $direction = $this->routeLabel();

        if (! str_contains($direction, '→')) {
            return $direction;
        }

        [, $to] = array_map('trim', explode('→', $direction, 2));

        return $to !== '' ? 'To '.$to : $direction;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMobileAssignment(): array
    {
        $this->loadMissing('boat');

        $time = Carbon::parse($this->departure_time);
        $order = (int) ($this->rotation_order ?: 1);

        return [
            'id' => $this->id,
            'role' => $this->isOutbound() ? 'going' : 'returning',
            'trip_type' => $this->tripTypeLabel(),
            'cycle_number' => $this->cycleNumber(),
            'trip_number_label' => $this->tripNumberLabel(),
            'return_of_id' => $this->return_of_id,
            'route' => $this->route,
            'direction' => $this->routeLabel(),
            'is_outbound' => $this->isOutbound(),
            'heading_label' => $this->headingLabel(),
            'rotation_order' => $order,
            'leave_order_label' => static::leaveOrderLabel($order),
            'trip_date' => $this->trip_date->toDateString(),
            'trip_date_label' => $this->trip_date->format('M d, Y'),
            'schedule_mode' => $this->scheduleMode()->value,
            'has_departure_time' => $this->hasScheduledTime(),
            'departure_time' => $this->hasScheduledTime() ? $time->format('H:i') : null,
            'departure_time_label' => $this->scheduledTimeLabel(),
            'actual_departure_label' => $this->actualDepartureLabel(),
            'actual_arrival_label' => $this->actualArrivalLabel(),
            'boarding' => $this->boardingPayload(),
            'departed_at' => $this->departed_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'passenger_count' => (int) $this->passenger_count,
            'status' => $this->status->value,
            'status_label' => $this->workflowStatusLabel(),
            'can_depart' => $this->isOutbound() && $this->status->isWaiting(),
            'can_arrive_destination' => false,
            'can_arrive_origin' => $this->isOutbound() && $this->status === RegularTripStatus::OnTrip,
            'can_complete_return' => ! $this->isOutbound() && $this->arrived_at !== null && $this->status !== RegularTripStatus::Completed,
            'can_encode_passengers' => $this->canEncodePassengers(),
            'boat' => [
                'id' => $this->boat?->id,
                'name' => $this->boat?->name,
                'code' => $this->boat?->code,
                'capacity' => $this->boat?->capacity,
            ],
        ];
    }

    public static function reverseDirection(?string $direction): ?string
    {
        if (! is_string($direction) || $direction === '' || ! str_contains($direction, '→')) {
            return $direction;
        }

        [$from, $to] = array_map('trim', explode('→', $direction, 2));

        if ($from === '' || $to === '') {
            return $direction;
        }

        return $to.' → '.$from;
    }

    public function isOutbound(): bool
    {
        $direction = $this->direction;
        $route = $this->route;

        if (! is_string($direction) || $direction === '' || ! is_string($route) || ! str_contains($route, '↔')) {
            return true;
        }

        [$origin, $destination] = array_map('trim', explode('↔', $route, 2));

        return $direction === $origin.' → '.$destination;
    }

    public static function slotDepartureTime(int $order, bool $returning = false): string
    {
        $hour = 6 + ((max(1, $order) - 1) * 2);

        if ($returning) {
            $hour += 1;
        }

        return sprintf('%02d:00', min(max($hour, 0), 23));
    }

    public static function ordinalLabel(int $order): string
    {
        $order = max(1, $order);
        $suffix = match ($order % 100) {
            11, 12, 13 => 'th',
            default => match ($order % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            },
        };

        return $order.$suffix;
    }

    public static function leaveOrderLabel(int $order): string
    {
        return static::ordinalLabel($order).' to leave';
    }

    public static function returnOrderLabel(int $order): string
    {
        return static::ordinalLabel($order).' to return';
    }

    public function scheduleMode(): RegularTripScheduleMode
    {
        return RegularTripScheduleMode::Rush;
    }

    public function isRushSchedule(): bool
    {
        return $this->scheduleMode() === RegularTripScheduleMode::Rush;
    }

    public function cycleNumber(): int
    {
        return max(1, (int) ($this->cycle_number ?: 1));
    }

    public function tripNumberLabel(): string
    {
        return 'Trip '.$this->cycleNumber();
    }

    public function tripTypeLabel(): string
    {
        return $this->isOutbound() ? 'Going' : 'Return';
    }

    public function hasScheduledTime(): bool
    {
        return false;
    }

    public function scheduledTimeLabel(): string
    {
        if (! $this->isOutbound()) {
            return 'No set time';
        }

        if (! $this->hasScheduledTime()) {
            return 'Leave When Ready';
        }

        return Carbon::parse($this->departure_time)->format('g:i A');
    }

    public function departureTimeLabel(): string
    {
        return $this->scheduledTimeLabel();
    }

    public function clockLabel(?Carbon $at): ?string
    {
        return $at?->copy()->timezone('Asia/Manila')->format('g:i A');
    }

    public function actualDepartureLabel(): string
    {
        return $this->clockLabel($this->departed_at) ?? 'Not Recorded';
    }

    public function actualArrivalLabel(): string
    {
        return $this->clockLabel($this->arrived_at) ?? 'Not Recorded';
    }

    public function workflowStatusLabel(): string
    {
        if ($this->status === RegularTripStatus::Completed || $this->remarks === 'completed_today') {
            return 'Completed';
        }

        if ($this->status === RegularTripStatus::OffDuty) {
            return 'Off Duty';
        }

        if ($this->status === RegularTripStatus::Cancelled) {
            return 'Cancelled';
        }

        if ($this->status === RegularTripStatus::OnTrip) {
            return 'On Trip';
        }

        if (! $this->isOutbound() && $this->arrived_at !== null && $this->departed_at === null) {
            return 'Boarding';
        }

        return 'Waiting';
    }

    public function returnDepartureTime(): string
    {
        return Carbon::parse($this->departure_time)->addHour()->format('H:i');
    }

    public function boatCapacity(): int
    {
        $this->loadMissing('boat');

        return max(0, (int) $this->boat?->capacity);
    }

    public function remainingSeats(): int
    {
        return max(0, $this->boatCapacity() - $this->passengers()->count());
    }

    public function isAtCapacity(): bool
    {
        return $this->remainingSeats() === 0;
    }

    public function canEncodePassengers(): bool
    {
        if ($this->status === RegularTripStatus::Cancelled || $this->status === RegularTripStatus::Completed || $this->status === RegularTripStatus::OffDuty || $this->remarks === 'completed_today') {
            return false;
        }

        if ($this->boatCapacity() <= 0) {
            return false;
        }

        if ($this->departed_at !== null || $this->status === RegularTripStatus::OnTrip) {
            return false;
        }

        if ($this->isOutbound()) {
            return $this->status->isWaiting();
        }

        return $this->arrived_at !== null;
    }

    public function canDepartReturn(): bool
    {
        return ! $this->isOutbound()
            && $this->arrived_at !== null
            && $this->departed_at === null
            && $this->status !== RegularTripStatus::Completed
            && $this->status !== RegularTripStatus::Cancelled;
    }

    public function referenceNumber(): string
    {
        return sprintf('RT-%s-%04d', $this->trip_date->format('Ymd'), $this->id);
    }

    public function waitingDurationLabel(): ?string
    {
        if ($this->boarding_started_at === null || $this->departed_at === null) {
            return null;
        }

        $minutes = (int) $this->boarding_started_at->diffInMinutes($this->departed_at);

        if ($minutes < 1) {
            return 'Less than 1 min';
        }

        return $minutes.' min';
    }

    public function syncPassengerCount(): void
    {
        $this->forceFill([
            'passenger_count' => $this->passengers()->count(),
        ])->save();
    }

    public static function defaultBoardingWaitMinutes(): int
    {
        return max(1, (int) config('safesakay.boarding_wait_minutes', 30));
    }

    public function boardingWaitMinutes(): int
    {
        $minutes = (int) ($this->boarding_wait_minutes ?: static::defaultBoardingWaitMinutes());

        return max(1, $minutes);
    }

    public function isFirstWaitingBoat(): bool
    {
        $first = static::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $this->organization_id)
            ->whereDate('trip_date', $this->trip_date)
            ->where('route', $this->route)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->orderByRaw('COALESCE(queue_order, rotation_order, 999)')
            ->orderBy('id')
            ->get()
            ->first(fn (self $trip) => $trip->isOutbound());

        return $first !== null && (int) $first->id === (int) $this->id;
    }

    public function canStartBoarding(): bool
    {
        if ($this->boarding_started_at !== null) {
            return false;
        }

        if ($this->isOutbound()) {
            return $this->status->isWaiting()
                && $this->trip_date->toDateString() === now()->toDateString()
                && $this->isFirstWaitingBoat();
        }

        return $this->arrived_at !== null
            && $this->departed_at === null
            && $this->status !== RegularTripStatus::Completed
            && $this->status !== RegularTripStatus::Cancelled;
    }

    public function startBoarding(): bool
    {
        if (! $this->canStartBoarding()) {
            return false;
        }

        $this->forceFill([
            'boarding_started_at' => now(),
            'boarding_wait_minutes' => $this->boardingWaitMinutes(),
        ])->save();

        return true;
    }

    public function boardingElapsedSeconds(): int
    {
        if ($this->boarding_started_at === null) {
            return 0;
        }

        return max(0, (int) $this->boarding_started_at->diffInSeconds(now()));
    }

    public function boardingRemainingSeconds(): int
    {
        $limit = $this->boardingWaitMinutes() * 60;

        return max(0, $limit - $this->boardingElapsedSeconds());
    }

    public function boardingLimitReached(): bool
    {
        return $this->boarding_started_at !== null
            && $this->boardingRemainingSeconds() === 0;
    }

    public function boardingPhase(): string
    {
        if ($this->departed_at !== null || $this->status === RegularTripStatus::OnTrip) {
            return 'departed';
        }

        $boardingOpen = $this->isOutbound()
            ? $this->status->isWaiting()
            : ($this->arrived_at !== null
                && $this->status !== RegularTripStatus::Completed
                && $this->status !== RegularTripStatus::Cancelled);

        if (! $boardingOpen) {
            return 'idle';
        }

        if ($this->isAtCapacity()) {
            return 'ready_to_depart';
        }

        if ($this->boardingLimitReached()) {
            return 'ready_for_review';
        }

        if ($this->boarding_started_at !== null) {
            return 'boarding';
        }

        return 'idle';
    }

    public function boardingPhaseLabel(): string
    {
        return match ($this->boardingPhase()) {
            'ready_to_depart' => 'Ready to Depart',
            'ready_for_review' => 'Ready for Review',
            'boarding' => 'Boarding',
            'departed' => 'Departed',
            default => 'Waiting',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function boardingPayload(): array
    {
        $minutes = $this->boardingWaitMinutes();
        $remaining = $this->boardingRemainingSeconds();
        $phase = $this->boardingPhase();
        $waiting = $boardingOpen = $this->isOutbound()
            ? $this->status->isWaiting()
            : ($this->arrived_at !== null
                && $this->departed_at === null
                && $this->status !== RegularTripStatus::Completed
                && $this->status !== RegularTripStatus::Cancelled);

        return [
            'active' => $waiting,
            'phase' => $phase,
            'phase_label' => $this->boardingPhaseLabel(),
            'wait_minutes' => $minutes,
            'started_at' => $this->boarding_started_at?->toIso8601String(),
            'started_at_label' => $this->clockLabel($this->boarding_started_at),
            'elapsed_seconds' => $this->boardingElapsedSeconds(),
            'remaining_seconds' => $remaining,
            'remaining_label' => $this->boardingRemainingLabel($remaining),
            'waiting_duration_label' => $this->waitingDurationLabel(),
            'limit_reached' => $this->boardingLimitReached(),
            'boat_full' => $this->isAtCapacity(),
            'ready_to_depart' => $boardingOpen && $this->isAtCapacity(),
            'ready_for_review' => $boardingOpen && $this->boardingLimitReached() && ! $this->isAtCapacity(),
            'auto_departs' => false,
            'note' => 'The 30-minute period is the maximum wait. The boat may depart with vacant seats once the operator confirms it is safe to leave.',
        ];
    }

    public function boardingRemainingLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Waiting limit reached';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' min remaining';
    }
}
