<?php

namespace App\Models;

use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $declared_by
 * @property int|null $announcement_id
 * @property string $code
 * @property WeatherHoldReason $reason
 * @property Carbon $affected_date
 * @property list<string> $affected_routes
 * @property string $duration_type
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string $announcement_message
 * @property WeatherSuspensionStatus $status
 * @property int $affected_trips_count
 * @property int $affected_rentals_count
 * @property int $review_flagged_count
 * @property int|null $lifted_by
 * @property Carbon|null $lifted_at
 * @property string|null $lift_notes
 */
#[Fillable([
    'organization_id',
    'declared_by',
    'announcement_id',
    'code',
    'reason',
    'affected_date',
    'affected_routes',
    'duration_type',
    'starts_at',
    'ends_at',
    'announcement_message',
    'status',
    'affected_trips_count',
    'affected_rentals_count',
    'review_flagged_count',
    'lifted_by',
    'lifted_at',
    'lift_notes',
])]
class WeatherSuspension extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => WeatherHoldReason::class,
            'affected_date' => 'date',
            'affected_routes' => 'array',
            'status' => WeatherSuspensionStatus::class,
            'affected_trips_count' => 'integer',
            'affected_rentals_count' => 'integer',
            'review_flagged_count' => 'integer',
            'lifted_at' => 'datetime',
        ];
    }

    public static function nextCode(): string
    {
        $year = (int) now()->year;
        $prefix = sprintf('WS-%d-', $year);

        $last = static::query()
            ->withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->reduce(function (int $max, mixed $code) use ($prefix): int {
                $suffix = substr((string) $code, strlen($prefix));

                return ctype_digit($suffix) ? max($max, (int) $suffix) : $max;
            }, 0);

        return sprintf('WS-%d-%05d', $year, $last + 1);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return HasMany<BoatBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(BoatBooking::class);
    }

    /**
     * @return HasMany<RegularTrip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(RegularTrip::class);
    }

    public function isActive(): bool
    {
        return $this->status === WeatherSuspensionStatus::Active;
    }

    public function coversAllRoutes(): bool
    {
        $routes = collect($this->affected_routes ?? [])
            ->map(fn ($route) => strtolower(trim((string) $route)))
            ->filter();

        return $routes->isEmpty() || $routes->contains('all');
    }

    public static function placeLabel(string $place): string
    {
        return mb_convert_case(trim($place), MB_CASE_TITLE, 'UTF-8');
    }

    public function affectedRoutesLabel(): string
    {
        if ($this->coversAllRoutes()) {
            return 'All routes';
        }

        return collect($this->affected_routes ?? [])
            ->map(fn ($route) => trim((string) $route))
            ->filter()
            ->map(fn (string $route) => self::placeLabel($route))
            ->implode(', ');
    }

    public function coversRoute(?string $route): bool
    {
        if ($this->coversAllRoutes() || ! filled($route)) {
            return true;
        }

        $needle = strtolower(trim($route));

        return collect($this->affected_routes ?? [])
            ->contains(function (mixed $item) use ($needle): bool {
                $value = strtolower(trim((string) $item));

                return $value === $needle || str_contains($needle, $value) || str_contains($value, $needle);
            });
    }

    public function coversTime(?string $time): bool
    {
        if ($this->duration_type !== 'time_range' || ! $this->starts_at || ! $this->ends_at) {
            return true;
        }

        if (! filled($time)) {
            return true;
        }

        $slot = Carbon::parse($time)->format('H:i');
        $start = Carbon::parse((string) $this->starts_at)->format('H:i');
        $end = Carbon::parse((string) $this->ends_at)->format('H:i');

        return $slot >= $start && $slot <= $end;
    }

    public function covers(string $date, ?string $route = null, ?string $time = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (! $this->isRedDate($date)) {
            return false;
        }

        return $this->coversRoute($route) && $this->coversTime($time);
    }

    public function redEndDate(?string $today = null): string
    {
        $today ??= now('Asia/Manila')->toDateString();
        $start = $this->affected_date->toDateString();

        return $start > $today ? $start : $today;
    }

    public function watchDate(?string $today = null): string
    {
        return Carbon::parse($this->redEndDate($today))->addDay()->toDateString();
    }

    public function isRedDate(string $date, ?string $today = null): bool
    {
        $value = Carbon::parse($date)->toDateString();
        $start = $this->affected_date->toDateString();
        $end = $this->redEndDate($today);

        return $value >= $start && $value <= $end;
    }

    public function isWatchDate(string $date, ?string $today = null): bool
    {
        return Carbon::parse($date)->toDateString() === $this->watchDate($today);
    }

    public function reasonLabel(): string
    {
        return $this->reason->label();
    }

    public function durationLabel(): string
    {
        if ($this->duration_type === 'time_range' && $this->starts_at && $this->ends_at) {
            return Carbon::parse((string) $this->starts_at)->format('g:i A')
                .' – '
                .Carbon::parse((string) $this->ends_at)->format('g:i A');
        }

        return 'Whole Day';
    }
}
