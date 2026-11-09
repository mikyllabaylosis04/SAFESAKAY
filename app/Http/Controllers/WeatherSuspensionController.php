<?php

namespace App\Http\Controllers;

use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\OrganizationRoute;
use App\Models\WeatherSuspension;
use App\Services\WeatherSuspensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WeatherSuspensionController extends Controller
{
    public function store(Request $request, WeatherSuspensionService $weather): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::enum(WeatherHoldReason::class)],
            'affected_date' => ['required', 'date'],
            'affected_routes' => ['required', 'array', 'min:1'],
            'affected_routes.*' => ['required', 'string', 'max:120'],
            'duration_type' => ['required', 'in:whole_day,time_range'],
            'starts_at' => ['required_if:duration_type,time_range', 'nullable', 'date_format:H:i'],
            'ends_at' => ['required_if:duration_type,time_range', 'nullable', 'date_format:H:i', 'after:starts_at'],
            'announcement_message' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $weather->declare($request->user(), $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Affected trips are suspended. Upcoming rentals are on Weather Hold. Verified downpayments were not changed.',
        ]);

        return back();
    }

    public function lift(Request $request, WeatherSuspension $weatherSuspension, WeatherSuspensionService $weather): RedirectResponse
    {
        $data = $request->validate([
            'lift_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $weather->lift($weatherSuspension, $request->user(), $data['lift_notes'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Weather suspension lifted. Pending reschedules were not auto-approved.',
        ]);

        return back();
    }

    public function extend(Request $request, WeatherSuspension $weatherSuspension, WeatherSuspensionService $weather): RedirectResponse
    {
        $data = $request->validate([
            'duration_type' => ['required', 'in:whole_day,time_range'],
            'starts_at' => ['required_if:duration_type,time_range', 'nullable', 'date_format:H:i'],
            'ends_at' => ['required_if:duration_type,time_range', 'nullable', 'date_format:H:i', 'after:starts_at'],
            'announcement_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $weather->extend($weatherSuspension, $data, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Weather suspension updated.',
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function activeTodayAlert(?string $date = null): ?array
    {
        $date ??= now('Asia/Manila')->toDateString();

        return collect(self::activeHoldDates())
            ->first(fn (array $mark) => ($mark['affected_date'] ?? null) === $date && ($mark['tone'] ?? 'red') === 'red');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function activeWatchAlert(?string $date = null): ?array
    {
        $date ??= now('Asia/Manila')->toDateString();
        $tomorrow = \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString();

        return collect(self::activeHoldDates())
            ->first(fn (array $mark) => ($mark['tone'] ?? null) === 'yellow'
                && in_array($mark['affected_date'] ?? null, [$date, $tomorrow], true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activeHoldDates(): array
    {
        if (! Schema::hasTable('weather_suspensions')) {
            return [];
        }

        return app(WeatherSuspensionService::class)->calendarMarks();
    }

    /**
     * @return array<string, mixed>
     */
    private static function alertPayload(WeatherSuspension $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'reason' => $item->reason->value,
            'reason_label' => $item->reasonLabel(),
            'headline' => 'No trips due to '.$item->reasonLabel(),
            'title' => 'Trip suspension — '.$item->reasonLabel(),
            'message' => $item->announcement_message,
            'affected_date' => $item->affected_date->toDateString(),
            'affected_date_label' => $item->affected_date->format('M d, Y'),
            'affected_routes_label' => $item->affectedRoutesLabel(),
            'duration_label' => $item->durationLabel(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function records(): array
    {
        if (! Schema::hasTable('weather_suspensions')) {
            return [];
        }

        return WeatherSuspension::query()
            ->with(['declarer', 'lifter'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (WeatherSuspension $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'reason' => $item->reason->value,
                'reason_label' => $item->reasonLabel(),
                'affected_date' => $item->affected_date->toDateString(),
                'affected_date_label' => $item->affected_date->format('M d, Y'),
                'affected_routes' => $item->affected_routes,
                'affected_routes_label' => $item->affectedRoutesLabel(),
                'duration_type' => $item->duration_type,
                'duration_label' => $item->durationLabel(),
                'announcement_message' => $item->announcement_message,
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'affected_trips_count' => $item->affected_trips_count,
                'affected_rentals_count' => $item->affected_rentals_count,
                'review_flagged_count' => $item->review_flagged_count,
                'declared_by_name' => $item->declarer?->name,
                'declared_at_label' => $item->created_at?->format('M d, Y g:i A'),
                'declared_date_label' => $item->created_at?->format('M d, Y'),
                'declared_time_label' => $item->created_at?->format('g:i A'),
                'declared_ago' => $item->created_at?->diffForHumans(),
                'lifted_by_name' => $item->lifter?->name,
                'lifted_at_label' => $item->lifted_at?->format('M d, Y g:i A'),
            ])
            ->values()
            ->all();
    }

    /**
     * Places only (one per destination); matching against trips and rentals is substring-based.
     *
     * @return list<array{value: string, label: string, group: string|null}>
     */
    public static function routeOptions(): array
    {
        $places = OrganizationRoute::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (OrganizationRoute $route) => [
                'value' => trim((string) ($route->destination ?: $route->name)),
                'group' => $route->isRegularTrip() ? 'Regular Trips' : 'Private Rentals',
            ])
            ->filter(fn (array $place) => $place['value'] !== '')
            ->unique(fn (array $place) => mb_strtolower($place['value']))
            ->sortBy(fn (array $place) => ($place['group'] === 'Regular Trips' ? '0' : '1').mb_strtolower($place['value']))
            ->map(fn (array $place) => [
                ...$place,
                'label' => WeatherSuspension::placeLabel($place['value']),
            ])
            ->values()
            ->all();

        return [
            ['value' => 'all', 'label' => 'All Routes', 'group' => null],
            ...$places,
        ];
    }
}
