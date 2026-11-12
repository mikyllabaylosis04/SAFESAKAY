<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRouteType;
use App\Enums\PassengerFareType;
use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Http\Requests\StoreRegularTripRequest;
use App\Models\Boat;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Services\RegularTripRotationService;
use App\Services\WeatherSuspensionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegularTripController extends Controller
{
    /** @var Collection<int, OrganizationRoute>|null */
    private ?Collection $fareRoutes = null;

    public function index(RegularTripRotationService $rotation): Response
    {
        $organizationId = auth()->user()?->organization_id;

        if ($organizationId) {
            $rotation->syncUpcoming($organizationId);
            $rotation->pruneInactiveBoatsFromLineup($organizationId);
            $rotation->compactOpenWaitingQueues($organizationId);
            $rotation->ensureReturnsForDay($organizationId);
            $rotation->refreshBoardingForDay($organizationId);
            $rotation->restoreFinishedPrivateBookingTrips($organizationId);
        }

        $trips = RegularTrip::query()
            ->with(['boat.operator', 'passengers', 'goingTrip'])
            ->withCount('passengers')
            ->orderBy('trip_date')
            ->orderBy('rotation_order')
            ->orderBy('id')
            ->get()
            ->reject(fn (RegularTrip $trip) => $trip->boat?->hasRentalBlockingRegularTripOn($trip->trip_date) === true)
            ->values();

        $payload = $this->decorateLineup($trips);

        $boats = Boat::query()
            ->with('operator')
            ->orderBy('name')
            ->get();

        $tripBoats = $boats
            ->filter(fn (Boat $boat) => $boat->isEligibleForRegularTrip())
            ->values();

        $routes = OrganizationRoute::query()
            ->where('trip_type', OrganizationRouteType::RegularTrip->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (OrganizationRoute $route) => $route->toOption())
            ->values();

        $primaryRoute = $routes->first();

        return Inertia::render('schedules/RegularTripRotation', [
            'trips' => $payload,
            'boats' => $boats->map(fn (Boat $boat) => $this->transformBoat($boat)),
            'tripBoats' => $tripBoats->map(fn (Boat $boat) => $this->transformBoat($boat)),
            'organizationRoutes' => $routes,
            'routeOptions' => $routes->pluck('name')->all(),
            'ports' => [
                'origin' => is_array($primaryRoute) ? (string) ($primaryRoute['origin'] ?? '') : '',
                'destination' => is_array($primaryRoute) ? (string) ($primaryRoute['destination'] ?? '') : '',
            ],
            'weatherAlert' => WeatherSuspensionController::activeTodayAlert(),
            'statusOptions' => collect(RegularTripStatus::monitorable())
                ->map(fn (RegularTripStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),
        ]);
    }

    public function store(StoreRegularTripRequest $request, RegularTripRotationService $rotation): RedirectResponse
    {
        $data = $request->validated();
        $data['status'] = RegularTripStatus::Scheduled;
        $data['passenger_count'] = 0;
        $data['is_auto_generated'] = false;
        $data['cycle_number'] = $data['cycle_number'] ?? 1;
        $data['schedule_mode'] = RegularTripScheduleMode::Rush;
        $data['boarding_wait_minutes'] = RegularTrip::defaultBoardingWaitMinutes();
        $data['queue_order'] = $data['rotation_order'] ?? null;

        $trip = RegularTrip::query()->create($data);

        $rotation->recordHistory($trip);
        $return = $rotation->ensureReturnTrip($trip);
        $rotation->refreshBoardingForDay($trip->organization_id, $trip->trip_date->toDateString());

        if ($trip->organization_id) {
            $rotation->syncUpcoming($trip->organization_id, refreshAutoDays: true);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $return?->wasRecentlyCreated
                ? 'Boat added to the lineup. It leaves when ready. Return to origin is automatic.'
                : 'Boat added to the rotation.',
        ]);

        return to_route('schedules.regular-trip-rotation');
    }

    public function update(StoreRegularTripRequest $request, RegularTrip $regularTrip, RegularTripRotationService $rotation): RedirectResponse
    {
        $data = $request->validated();
        unset($data['status'], $data['passenger_count']);
        $data['is_auto_generated'] = false;

        $regularTrip->update($data);
        $rotation->syncReturnTrip($regularTrip->fresh());

        if ($regularTrip->organization_id) {
            $rotation->syncUpcoming($regularTrip->organization_id, refreshAutoDays: true);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Trip updated. The return today and the next days follow this change.',
        ]);

        return to_route('schedules.regular-trip-rotation');
    }

    public function updateScheduleMode(Request $request, RegularTripRotationService $rotation): RedirectResponse
    {
        $data = $request->validate([
            'trip_date' => ['required', 'date'],
            'schedule_mode' => ['required', Rule::enum(RegularTripScheduleMode::class)],
        ]);

        $organizationId = auth()->user()?->organization_id;

        if ($organizationId) {
            $rotation->setDayScheduleMode(
                $organizationId,
                $data['trip_date'],
                RegularTripScheduleMode::from($data['schedule_mode']),
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Regular trips leave when ready. There is no fixed departure clock.',
        ]);

        return to_route('schedules.regular-trip-rotation');
    }

    public function show(RegularTrip $regularTrip, RegularTripRotationService $rotation): Response
    {
        $rotation->refreshBoarding($regularTrip);
        $regularTrip->load([
            'boat.operator',
            'passengers',
            'goingTrip.passengers',
            'returnTrips.passengers',
        ]);

        $historyTrips = collect($rotation->boatHistory($regularTrip))->values();
        $history = $historyTrips
            ->map(function (RegularTrip $item, int $index) {
                $revenue = $this->tripRevenue($item);
                $tripRevenue = round((float) $revenue['revenue'], 2);

                return [
                    'id' => $item->id,
                    'trip_number' => $index + 1,
                    'leg_label' => $item->isOutbound() ? 'Going' : 'Return',
                    'date' => $item->trip_date->toDateString(),
                    'date_label' => $item->trip_date->format('F j, Y'),
                    'departure_time_label' => $item->clockLabel($item->departed_at) ?? 'Not Recorded',
                    'arrived_at_label' => $item->clockLabel($item->arrived_at) ?? 'Not Recorded',
                    'completed_at_label' => $item->clockLabel($item->completed_at) ?? 'Not Recorded',
                    'boarding_started_at_label' => $item->clockLabel($item->boarding_started_at),
                    'route' => $item->direction ?: $item->route,
                    'passenger_count' => (int) ($item->passengers_count ?? $item->passenger_count),
                    'revenue' => $tripRevenue,
                    'revenue_label' => OrganizationRoute::formatPeso($tripRevenue),
                    'status' => $item->status->value,
                    'status_label' => $item->workflowStatusLabel(),
                ];
            })
            ->all();

        $tripPayload = $this->transform($regularTrip, withPassengers: true, tripsToday: $rotation->completedTripsToday($regularTrip));
        $boatToday = (float) collect($history)->sum('revenue');
        $tripPayload['boat_revenue_today'] = round($boatToday, 2);
        $tripPayload['boat_revenue_today_label'] = OrganizationRoute::formatPeso($boatToday);
        $tripPayload = array_merge($tripPayload, $this->collectedRevenueForBoatDay($regularTrip));
        $tripPayload['paired_return'] = $this->pairedReturnPayload($regularTrip);

        return Inertia::render('schedules/RegularTripDetails', [
            'trip' => $tripPayload,
            'tripHistory' => $history,
            'statusOptions' => collect(RegularTripStatus::monitorable())
                ->map(fn (RegularTripStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),
        ]);
    }

    public function startBoarding(RegularTrip $regularTrip, RegularTripRotationService $rotation): JsonResponse
    {
        $rotation->beginBoarding($regularTrip);
        $regularTrip->refresh();

        return response()->json([
            'started' => $regularTrip->boarding_started_at !== null,
            'started_at_label' => $regularTrip->clockLabel($regularTrip->boarding_started_at),
        ]);
    }

    public function updateStatus(
        Request $request,
        RegularTrip $regularTrip,
        RegularTripRotationService $rotation,
    ): RedirectResponse {
        $allowed = collect(RegularTripStatus::cases())
            ->map(fn (RegularTripStatus $status) => $status->value)
            ->all();

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($allowed)],
            'action' => ['sometimes', 'string', Rule::in(['depart', 'arrive', 'complete'])],
            'return_to_queue' => ['sometimes', 'boolean'],
            'duty_note' => ['sometimes', 'string', Rule::in(['off_duty', 'completed_today'])],
        ]);

        $status = RegularTripStatus::from($data['status']);
        $action = $data['action'] ?? null;
        $returnToQueue = $request->boolean('return_to_queue', true);

        try {
            $regularTrip = match (true) {
                $action === 'depart' && ! $regularTrip->isOutbound() => $rotation->departReturn($regularTrip),
                $action === 'depart' || ($status === RegularTripStatus::OnTrip && $regularTrip->isOutbound() && $action !== 'arrive') => $rotation->depart($regularTrip),
                $action === 'arrive' => $rotation->arriveBackAtOrigin($regularTrip),
                $status === RegularTripStatus::OnTrip && ! $regularTrip->isOutbound() => $rotation->arriveBackAtOrigin($regularTrip),
                $action === 'complete' && ! $regularTrip->isOutbound() => $rotation->completeReturn($regularTrip, $returnToQueue),
                $status === RegularTripStatus::Completed => $rotation->complete($regularTrip, $returnToQueue),
                $status === RegularTripStatus::OffDuty => $rotation->setOffDuty($regularTrip, (string) ($data['duty_note'] ?? 'off_duty')),
                $status === RegularTripStatus::Scheduled => $rotation->setOnDuty($regularTrip),
                default => tap($regularTrip)->update(['status' => $status]),
            };
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Could not update this boat.',
            ]);

            return back()->withErrors($exception->errors());
        }

        $message = match (true) {
            $action === 'depart' && ! $regularTrip->isOutbound() => 'Return departure recorded. The passenger list is locked.',
            $action === 'arrive' || (! $regularTrip->isOutbound() && $regularTrip->arrived_at && $regularTrip->status !== RegularTripStatus::Completed && $regularTrip->departed_at === null) => 'Boat is back at the origin. Add the passengers who rode home, then complete the trip.',
            $action === 'complete' || ($status === RegularTripStatus::Completed && ! $regularTrip->isOutbound() && $regularTrip->status === RegularTripStatus::Completed) => $returnToQueue
                ? 'Round trip completed. The boat is waiting at the last rotation position.'
                : 'Round trip completed. The operator has ended duty for today.',
            $status === RegularTripStatus::OnTrip => 'Boat departed. Actual departure time recorded. The passenger list is locked.',
            $status === RegularTripStatus::Completed && $regularTrip->isOutbound() => 'Going trip completed. Return boarding can start.',
            $status === RegularTripStatus::OffDuty => 'Duty updated. The boat stays on today’s list with no queue number.',
            $status === RegularTripStatus::Scheduled => 'Boat is waiting at the last rotation position.',
            $status === RegularTripStatus::Cancelled => 'Trip cancelled.',
            default => 'Trip status updated.',
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        $updated = $regularTrip->fresh();
        $referer = (string) $request->headers->get('referer', '');
        $fromRotation = str_contains($referer, '/schedules/regular-trip-rotation');

        if (
            $action === 'arrive'
            && $updated
            && ! $updated->isOutbound()
            && $updated->arrived_at !== null
            && $updated->status !== RegularTripStatus::Completed
        ) {
            return to_route('regular-trips.show', [
                'regularTrip' => $updated,
                'encode' => 1,
            ]);
        }

        if ($fromRotation || $status === RegularTripStatus::Completed) {
            return to_route('schedules.regular-trip-rotation');
        }

        return to_route('regular-trips.show', $updated);
    }

    public function destroy(RegularTrip $regularTrip, RegularTripRotationService $rotation): RedirectResponse
    {
        $rotation->removeFromRotation($regularTrip);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Trip removed. The return today and the next days follow the remaining lineup.',
        ]);

        return to_route('schedules.regular-trip-rotation');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, RegularTrip>  $trips
     * @return list<array<string, mixed>>
     */
    private function decorateLineup(Collection $trips): array
    {
        return $trips
            ->groupBy(fn (RegularTrip $trip) => $trip->trip_date->toDateString().'|'.$trip->route)
            ->flatMap(function (Collection $group) {
                $outbound = $group->filter(fn (RegularTrip $trip) => $trip->isOutbound())->values();
                $returning = $group
                    ->filter(fn (RegularTrip $trip) => ! $trip->isOutbound()
                        && $trip->status !== RegularTripStatus::Completed
                        && $trip->status !== RegularTripStatus::Cancelled)
                    ->sortBy(function (RegularTrip $trip) use ($outbound) {
                        $going = $outbound->first(
                            fn (RegularTrip $item) => (int) $item->id === (int) $trip->return_of_id,
                        ) ?? $trip->goingTrip;

                        $departed = $going?->departed_at?->getTimestamp() ?? PHP_INT_MAX;

                        return sprintf('%015d-%03d-%010d', $departed, (int) ($trip->rotation_order ?: 999), $trip->id);
                    })
                    ->values();
                $waiting = $outbound
                    ->filter(fn (RegularTrip $trip) => $trip->status->isWaiting())
                    ->sortBy(fn (RegularTrip $trip) => sprintf(
                        '%05d-%010d',
                        (int) ($trip->queue_order ?: $trip->rotation_order ?: 999),
                        $trip->id,
                    ))
                    ->values();
                $completedToday = $group
                    ->filter(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::Completed)
                    ->countBy(fn (RegularTrip $trip) => (int) $trip->boat_id);
                $boatRevenueToday = $group
                    ->reject(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::Cancelled)
                    ->groupBy(fn (RegularTrip $trip) => (int) $trip->boat_id)
                    ->map(function (Collection $boatTrips) {
                        return round(
                            (float) $boatTrips->sum(fn (RegularTrip $item) => $this->tripRevenue($item)['revenue']),
                            2,
                        );
                    });

                return $group->map(function (RegularTrip $trip) use ($outbound, $waiting, $returning, $completedToday, $boatRevenueToday) {
                    if ($trip->isOutbound()) {
                        $rank = $waiting->search(fn (RegularTrip $item) => $item->id === $trip->id);
                        $queuePosition = is_int($rank) ? $rank + 1 : null;
                    } else {
                        $rank = $returning->search(fn (RegularTrip $item) => $item->id === $trip->id);
                        $queuePosition = is_int($rank)
                            ? $rank + 1
                            : ((int) ($trip->rotation_order ?: 0) ?: null);
                    }
                    $lineupId = $outbound
                        ->filter(fn (RegularTrip $item) => (int) $item->boat_id === (int) $trip->boat_id
                            && $item->status->isLineup())
                        ->sortByDesc('id')
                        ->first()
                        ?->id;

                    $payload = $this->transform(
                        $trip,
                        tripsToday: (int) $completedToday->get((int) $trip->boat_id, 0),
                        queuePosition: $queuePosition,
                        isLineup: $trip->isOutbound() && $lineupId === $trip->id,
                    );
                    $boatToday = (float) $boatRevenueToday->get((int) $trip->boat_id, 0);
                    $payload['boat_revenue_today'] = $boatToday;
                    $payload['boat_revenue_today_label'] = OrganizationRoute::formatPeso($boatToday);

                    return $payload;
                });
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(
        RegularTrip $trip,
        bool $withPassengers = false,
        int $tripsToday = 0,
        ?int $queuePosition = null,
        bool $isLineup = true,
    ): array {
        $time = Carbon::parse($trip->departure_time);
        $capacity = $trip->boatCapacity();
        $passengerCount = $trip->relationLoaded('passengers')
            ? $trip->passengers->count()
            : (int) ($trip->passengers_count ?? $trip->passenger_count);

        $queueLabel = match ($trip->status) {
            RegularTripStatus::OnTrip => 'On Trip',
            RegularTripStatus::OffDuty => '—',
            RegularTripStatus::Completed, RegularTripStatus::Cancelled => '—',
            default => $queuePosition ? RegularTrip::ordinalLabel($queuePosition) : '—',
        };

        $payload = [
            'id' => $trip->id,
            'route' => $trip->route,
            'direction' => $trip->direction ?: $trip->route,
            'is_outbound' => $trip->isOutbound(),
            'heading_label' => $trip->headingLabel(),
            'rotation_order' => (int) ($trip->rotation_order ?: 0),
            'queue_order' => $trip->queue_order ? (int) $trip->queue_order : null,
            'departed_at' => $trip->departed_at?->toIso8601String(),
            'status_changed_at' => ($trip->completed_at ?? $trip->updated_at)?->toIso8601String(),
            'leave_order_label' => $queuePosition
                ? ($trip->isOutbound()
                    ? RegularTrip::leaveOrderLabel($queuePosition)
                    : RegularTrip::returnOrderLabel($queuePosition))
                : $queueLabel,
            'queue_position' => $queuePosition,
            'queue_position_label' => $trip->isOutbound()
                ? $queueLabel
                : ($queuePosition ? RegularTrip::returnOrderLabel($queuePosition) : $queueLabel),
            'trips_today' => $tripsToday,
            'is_lineup' => $isLineup,
            'trip_type' => $trip->tripTypeLabel(),
            'cycle_number' => $trip->cycleNumber(),
            'trip_number_label' => $trip->tripNumberLabel(),
            'return_of_id' => $trip->return_of_id,
            'can_depart' => $this->canDepartRegularTrip($trip),
            'weather_review_required' => (bool) $trip->weather_review_required,
            'weather_suspension_id' => $trip->weather_suspension_id,
            'can_arrive_destination' => false,
            'can_arrive_origin' => $trip->isOutbound() && $trip->status === RegularTripStatus::OnTrip,
            'can_complete_return' => ! $trip->isOutbound()
                && $trip->arrived_at !== null
                && $trip->departed_at === null
                && ! in_array($trip->status, [
                    RegularTripStatus::Completed,
                    RegularTripStatus::OffDuty,
                    RegularTripStatus::Cancelled,
                ], true)
                && $trip->remarks !== 'completed_today',
            'can_depart_return' => $trip->canDepartReturn(),
            'can_encode_passengers' => $trip->canEncodePassengers(),
            'remaining_seats' => max(0, $capacity - $passengerCount),
            'at_capacity' => $capacity > 0 && $passengerCount >= $capacity,
            'can_complete' => $trip->isOutbound() && $trip->status === RegularTripStatus::OnTrip,
            'can_set_off_duty' => $trip->isOutbound() && $trip->status->isWaiting(),
            'can_set_on_duty' => $trip->status === RegularTripStatus::OffDuty,
            'reference_number' => $trip->referenceNumber(),
            'waiting_duration_label' => $trip->waitingDurationLabel(),
            'departed_at_label' => $trip->clockLabel($trip->departed_at) ?? 'Not Recorded',
            'arrived_at_label' => $trip->clockLabel($trip->arrived_at) ?? 'Not Recorded',
            'completed_at_label' => $trip->clockLabel($trip->completed_at) ?? 'Not Recorded',
            'trip_date' => $trip->trip_date->toDateString(),
            'trip_date_label' => $trip->trip_date->format('M d, Y'),
            'schedule_mode' => $trip->scheduleMode()->value,
            'has_departure_time' => $trip->hasScheduledTime(),
            'scheduled_time_label' => $trip->scheduledTimeLabel(),
            'departure_time' => $trip->hasScheduledTime() ? $time->format('H:i') : '',
            'departure_time_label' => $trip->scheduledTimeLabel(),
            'actual_departure_label' => $trip->actualDepartureLabel(),
            'actual_arrival_label' => $trip->actualArrivalLabel(),
            'boarding' => $trip->boardingPayload(),
            'passenger_count' => $passengerCount,
            'status' => $trip->status->value,
            'status_label' => $trip->workflowStatusLabel(),
            'remarks' => $trip->remarks,
            ...$this->tripRevenue($trip),
            'boat' => [
                'id' => $trip->boat->id,
                'name' => $trip->boat->name,
                'code' => $trip->boat->code,
                'capacity' => $capacity,
                'status' => $trip->boat->status->value,
                'status_label' => $trip->boat->status->label(),
                'is_active' => (bool) $trip->boat->is_active,
                'service_type' => $trip->boat->syncedServiceType(),
                'picture_url' => $trip->boat->pictureUrl(),
                'operator_name' => $trip->boat->operator?->full_name,
            ],
        ];

        if ($withPassengers) {
            $payload['boat_capacity'] = $capacity;
            $payload['remaining_seats'] = max(0, $capacity - $passengerCount);
            $payload['at_capacity'] = $capacity > 0 && $passengerCount >= $capacity;
            $payload['can_encode_passengers'] = $trip->canEncodePassengers();
            $payload['passengers'] = $trip->passengers
                ->map(function (TripPassenger $passenger) use ($trip) {
                    $record = $passenger->toRecord();
                    $amount = $this->fareRouteFor($trip)?->fareAmountFor($passenger->fareType());
                    $record['fare_amount'] = $amount;
                    $record['fare_amount_label'] = $amount === null
                        ? '—'
                        : OrganizationRoute::formatPeso($amount);

                    return $record;
                })
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function tripRevenue(RegularTrip $trip): array
    {
        $route = $this->fareRouteFor($trip);
        $passengers = $trip->relationLoaded('passengers') ? $trip->passengers : collect();
        $counts = [
            PassengerFareType::Regular->value => 0,
            PassengerFareType::Student->value => 0,
            PassengerFareType::Senior->value => 0,
        ];
        $total = 0.0;

        foreach ($passengers as $passenger) {
            $type = $passenger->fareType();
            $counts[$type->value]++;
            $total += $route?->fareAmountFor($type) ?? 0;
        }

        return [
            'revenue' => round($total, 2),
            'revenue_label' => OrganizationRoute::formatPeso($total),
            'fare_counts' => $counts,
            'fares' => $route?->fareTable() ?? [
                'regular_fare' => null,
                'regular_fare_label' => null,
                'student_fare' => null,
                'student_fare_label' => null,
                'senior_fare' => null,
                'senior_fare_label' => null,
                'discount_percent' => OrganizationRoute::FARE_DISCOUNT_PERCENT,
                'student_discount_percent' => OrganizationRoute::FARE_DISCOUNT_PERCENT,
                'senior_discount_percent' => OrganizationRoute::FARE_DISCOUNT_PERCENT,
            ],
        ];
    }

    /**
     * Outbound, return, and total fares already collected for this boat today.
     * A leg counts after it departs or the trip is completed.
     *
     * @return array<string, float|string>
     */
    private function collectedRevenueForBoatDay(RegularTrip $trip): array
    {
        $legs = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with('passengers')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->get();

        $outbound = 0.0;
        $return = 0.0;

        foreach ($legs as $leg) {
            $amount = $this->collectedFare($leg);

            if ($leg->isOutbound()) {
                $outbound += $amount;
            } else {
                $return += $amount;
            }
        }

        $outbound = round($outbound, 2);
        $return = round($return, 2);
        $total = round($outbound + $return, 2);

        return [
            'outbound_revenue' => $outbound,
            'outbound_revenue_label' => OrganizationRoute::formatPeso($outbound),
            'return_revenue' => $return,
            'return_revenue_label' => OrganizationRoute::formatPeso($return),
            'total_revenue' => $total,
            'total_revenue_label' => OrganizationRoute::formatPeso($total),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pairedReturnPayload(RegularTrip $trip): ?array
    {
        if (! $trip->isOutbound()) {
            return null;
        }

        $trip->loadMissing('returnTrips');

        $return = $trip->returnTrips
            ->sortByDesc('id')
            ->first(fn (RegularTrip $item) => $item->status !== RegularTripStatus::Cancelled && $item->arrived_at !== null);

        if (! $return) {
            return null;
        }

        $departed = $return->departed_at !== null
            || $return->status === RegularTripStatus::OnTrip
            || $return->status === RegularTripStatus::Completed;

        return [
            'id' => $return->id,
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            'started' => true,
            'departed' => $departed,
            'completed' => $return->status === RegularTripStatus::Completed,
        ];
    }

    private function collectedFare(RegularTrip $trip): float
    {
        $collected = $trip->departed_at !== null
            || $trip->status === RegularTripStatus::OnTrip
            || $trip->status === RegularTripStatus::Completed;

        if (! $collected) {
            return 0.0;
        }

        return (float) $this->tripRevenue($trip)['revenue'];
    }

    private function fareRouteFor(RegularTrip $trip): ?OrganizationRoute
    {
        return $this->regularFareRoutes()->first(
            fn (OrganizationRoute $route) => $route->matchesTrip(
                $trip->route,
                $trip->direction ?: $trip->route,
            ),
        );
    }

    /**
     * @return Collection<int, OrganizationRoute>
     */
    private function regularFareRoutes(): Collection
    {
        return $this->fareRoutes ??= OrganizationRoute::query()
            ->where('organization_id', auth()->user()?->organization_id)
            ->where('trip_type', OrganizationRouteType::RegularTrip->value)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBoat(Boat $boat): array
    {
        return [
            'id' => $boat->id,
            'name' => $boat->name,
            'code' => $boat->code,
            'capacity' => $boat->capacity,
            'status' => $boat->status->value,
            'service_type' => $boat->syncedServiceType(),
            'picture_url' => $boat->pictureUrl(),
            'operator_name' => $boat->operator?->full_name,
        ];
    }

    private function canDepartRegularTrip(RegularTrip $trip): bool
    {
        if (! $trip->isOutbound() || ! $trip->status->isWaiting()) {
            return false;
        }

        return app(WeatherSuspensionService::class)->departureBlockMessage(
            (int) $trip->organization_id,
            $trip->trip_date->toDateString(),
            $trip->direction ?: $trip->route,
            $trip->hasScheduledTime() ? Carbon::parse($trip->departure_time)->format('H:i') : null,
        ) === null;
    }
}
