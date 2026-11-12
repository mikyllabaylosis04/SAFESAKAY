<?php

namespace App\Services;

use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Organization;
use App\Models\RegularTrip;
use App\Models\User;
use App\Notifications\BoardingWaitReachedNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularTripRotationService
{
    public const ARCHIVE_HOLD_REMARK = 'Removed from lineup — boat archived.';

    public function syncUpcoming(?int $organizationId = null, int $daysAhead = 7, bool $refreshAutoDays = false): int
    {
        if ($organizationId) {
            return $this->syncOrganization($organizationId, $daysAhead, $refreshAutoDays);
        }

        $created = 0;

        Organization::query()->each(function (Organization $organization) use (&$created, $daysAhead, $refreshAutoDays): void {
            $created += $this->syncOrganization($organization->id, $daysAhead, $refreshAutoDays);
        });

        return $created;
    }

    public function syncOrganization(int $organizationId, int $daysAhead = 7, bool $refreshAutoDays = false): int
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(max(1, $daysAhead));
        $created = 0;

        if ($refreshAutoDays) {
            $this->clearReplaceableAutoDays($organizationId, $today->copy()->addDay(), $horizon);
        }

        for ($date = $today->copy(); $date->lte($horizon); $date->addDay()) {
            $existing = $this->tripsOn($organizationId, $date);

            if ($existing->isNotEmpty()) {
                continue;
            }

            if ($date->isSameDay($today)) {
                continue;
            }

            $source = $this->tripsOn($organizationId, $date->copy()->subDay());

            if ($source->isEmpty()) {
                continue;
            }

            $created += $this->createRotatedDay($organizationId, $source, $date);
        }

        return $created;
    }

    public function ensureReturnTrip(RegularTrip $trip): ?RegularTrip
    {
        $trip->loadMissing([
            'boat' => fn ($query) => $query->withoutGlobalScope('organization'),
        ]);

        if (! $trip->isOutbound()) {
            return null;
        }

        $reverse = RegularTrip::reverseDirection($trip->direction);

        if (! $reverse || $reverse === $trip->direction) {
            return null;
        }

        $existing = $this->pairedReturn($trip);

        if ($existing && (int) $existing->return_of_id === (int) $trip->id) {
            if (! in_array($existing->status, [RegularTripStatus::Completed, RegularTripStatus::Cancelled], true)) {
                $existing->update([
                    'boat_id' => $trip->boat_id,
                    'rotation_order' => $trip->rotation_order ?: 1,
                    'cycle_number' => $trip->cycleNumber(),
                    'schedule_mode' => RegularTripScheduleMode::Rush,
                ]);
            }

            return $existing->fresh();
        }

        if (! $trip->boat || ! $trip->boat->isEligibleForRegularTrip() || $trip->boat->hasCommittedPrivateBookingOn($trip->trip_date)) {
            return null;
        }

        $return = RegularTrip::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $trip->organization_id,
            'boat_id' => $trip->boat_id,
            'return_of_id' => $trip->id,
            'route' => $trip->route,
            'direction' => $reverse,
            'rotation_order' => $trip->rotation_order ?: 1,
            'cycle_number' => $trip->cycleNumber(),
            'schedule_mode' => RegularTripScheduleMode::Rush,
            'trip_date' => $trip->trip_date->toDateString(),
            'departure_time' => $trip->returnDepartureTime(),
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'remarks' => null,
            'is_auto_generated' => (bool) $trip->is_auto_generated,
        ]);

        $this->recordHistory($return);

        return $return;
    }

    /**
     * Ensure every outbound trip for the day has a matching return slot.
     */
    public function ensureReturnsForDay(int $organizationId, ?string $date = null): int
    {
        $date ??= now()->toDateString();
        $created = 0;

        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with('boat')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', $date)
            ->orderBy('rotation_order')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->each(function (RegularTrip $trip) use (&$created): void {
                $return = $this->ensureReturnTrip($trip);

                if ($return?->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Depart an On Duty boat: mark On Trip, record departure, drop it from the waiting queue.
     */
    public function depart(RegularTrip $trip): RegularTrip
    {
        return DB::transaction(function () use ($trip) {
            $record = $this->lockTrip($trip);

            if (! $record->isOutbound()) {
                throw ValidationException::withMessages([
                    'status' => ['Depart from the going trip, not the return slot.'],
                ]);
            }

            if (! $record->status->isWaiting()) {
                throw ValidationException::withMessages([
                    'status' => $record->status === RegularTripStatus::OnTrip
                        ? ['This boat is already on a trip.']
                        : ['Only On Duty boats can depart.'],
                ]);
            }

            if ($this->boatHasActiveTrip($record, excludeId: $record->id)) {
                throw ValidationException::withMessages([
                    'status' => ['This boat already has an active trip.'],
                ]);
            }

            $record->loadMissing('boat');

            if ($record->boat?->isOutOfService()) {
                throw ValidationException::withMessages([
                    'status' => ['This boat is under maintenance or unavailable and cannot depart.'],
                ]);
            }

            if ($record->boat?->hasCommittedPrivateBookingOn($record->trip_date)) {
                throw ValidationException::withMessages([
                    'status' => ['This boat has an exclusive rental and cannot depart a regular trip.'],
                ]);
            }

            $weatherBlock = app(WeatherSuspensionService::class)->departureBlockMessage(
                (int) $record->organization_id,
                $record->trip_date->toDateString(),
                $record->direction ?: $record->route,
                $record->hasScheduledTime() ? Carbon::parse($record->departure_time)->format('H:i') : null,
            );

            if ($weatherBlock) {
                throw ValidationException::withMessages([
                    'status' => [$weatherBlock],
                ]);
            }

            $record->update([
                'status' => RegularTripStatus::OnTrip,
                'departed_at' => now(),
                'queue_order' => null,
            ]);
            $this->refreshBoardingForDay((int) $record->organization_id, $record->trip_date->toDateString());

            return $record->fresh();
        });
    }

    public function departReturn(RegularTrip $trip): RegularTrip
    {
        return DB::transaction(function () use ($trip) {
            $record = $this->lockTrip($trip);

            if ($record->isOutbound() || ! $record->canDepartReturn()) {
                throw ValidationException::withMessages([
                    'status' => ['The return trip can depart only after the boat is back at the origin.'],
                ]);
            }

            $record->update([
                'status' => RegularTripStatus::OnTrip,
                'departed_at' => now(),
            ]);

            return $record->fresh();
        });
    }

    /**
     * There is no Placer station, so destination arrival is not recorded.
     * Arriving back at Lakandula closes the going trip and opens return passenger encoding.
     *
     * @deprecated Use arriveBackAtOrigin() — kept so older arrive callers still work.
     */
    public function arriveAtDestination(RegularTrip $trip): RegularTrip
    {
        return $this->arriveBackAtOrigin($trip);
    }

    /**
     * Boat is back at Lakandula. Record no Placer arrival. Open the return manifest.
     */
    public function arriveBackAtOrigin(RegularTrip $trip): RegularTrip
    {
        return DB::transaction(function () use ($trip) {
            $record = $this->lockTrip($trip);

            if ($record->isOutbound()) {
                return $this->closeGoingAndArriveReturn($record);
            }

            return $this->markReturnArrived($record);
        });
    }

    /**
     * Confirm the boat has arrived back at Lakandula. Do not invent a return departure time.
     */
    public function arriveAtOrigin(RegularTrip $trip): RegularTrip
    {
        return $this->arriveBackAtOrigin($trip);
    }

    private function closeGoingAndArriveReturn(RegularTrip $going): RegularTrip
    {
        if ($going->status !== RegularTripStatus::OnTrip && $going->status !== RegularTripStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['Depart before marking the boat as arrived at Lakandula.'],
            ]);
        }

        if ($going->status === RegularTripStatus::OnTrip) {
            $going->update([
                'status' => RegularTripStatus::Completed,
                'completed_at' => now(),
                'arrived_at' => null,
            ]);

            $this->recordCompletedTrip($going->fresh());
        }

        $return = $this->ensureReturnTrip($going->fresh());

        if (! $return) {
            throw ValidationException::withMessages([
                'status' => ['The return trip could not be opened.'],
            ]);
        }

        return $this->markReturnArrived($this->lockTrip($return));
    }

    private function markReturnArrived(RegularTrip $returning): RegularTrip
    {
        if ($returning->isOutbound()) {
            throw ValidationException::withMessages([
                'status' => ['Confirm origin arrival on the return trip.'],
            ]);
        }

        if ($returning->status === RegularTripStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['This return trip is already completed.'],
            ]);
        }

        $going = $this->pairedGoing($returning);

        if ($going && $going->status === RegularTripStatus::OnTrip) {
            return $this->closeGoingAndArriveReturn($this->lockTrip($going));
        }

        if (! $going || $going->status !== RegularTripStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['This boat must depart before it can arrive back at Lakandula.'],
            ]);
        }

        $returning->update([
            'arrived_at' => $returning->arrived_at ?? now(),
        ]);

        return $returning->fresh();
    }

    /**
     * Finish the return trip, then optionally rejoin the waiting queue at the end.
     */
    public function completeReturn(RegularTrip $trip, bool $returnToQueue): RegularTrip
    {
        return DB::transaction(function () use ($trip, $returnToQueue) {
            $record = $this->lockTrip($trip);

            if ($record->isOutbound()) {
                throw ValidationException::withMessages([
                    'status' => ['Complete the return trip after arrival at the origin.'],
                ]);
            }

            if ($record->status === RegularTripStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => ['This return trip is already completed.'],
                ]);
            }

            if ($record->arrived_at === null) {
                throw ValidationException::withMessages([
                    'status' => ['Mark the boat as arrived at the origin before completing the return trip.'],
                ]);
            }

            $going = $this->pairedGoing($record);

            $record->update([
                'status' => RegularTripStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->recordCompletedTrip($record->fresh());

            if ($going) {
                if ($returnToQueue) {
                    $this->openNextCycle($going->fresh());
                } else {
                    $this->placeOffDuty($going->fresh());
                }

                $this->compactWaitingQueue($going);
            }

            return $record->fresh();
        });
    }

    /**
     * @deprecated Use arriveAtDestination() or completeReturn() for the split going/return workflow.
     */
    public function complete(RegularTrip $trip, bool $returnToQueue): RegularTrip
    {
        $record = $trip->fresh() ?? $trip;

        if ($record->isOutbound()) {
            return $this->arriveBackAtOrigin($record);
        }

        if ($record->arrived_at === null) {
            return $this->arriveAtOrigin($record);
        }

        return $this->completeReturn($record, $returnToQueue);
    }

    public function setOffDuty(RegularTrip $trip, string $dutyNote = 'off_duty'): RegularTrip
    {
        return DB::transaction(function () use ($trip, $dutyNote) {
            $record = $this->lockTrip($trip);

            if ($record->status === RegularTripStatus::OnTrip) {
                throw ValidationException::withMessages([
                    'status' => ['Finish the active trip before ending duty.'],
                ]);
            }

            if ($record->status === RegularTripStatus::Completed) {
                return $record;
            }

            if (! $record->status->isWaiting() && $record->status !== RegularTripStatus::OffDuty) {
                throw ValidationException::withMessages([
                    'status' => ['Only waiting boats can be marked off duty or completed for today.'],
                ]);
            }

            $record->update([
                'status' => RegularTripStatus::OffDuty,
                'queue_order' => null,
                'remarks' => $dutyNote === 'completed_today' ? 'completed_today' : 'off_duty',
            ]);

            $this->markPairedReturn($record, RegularTripStatus::OffDuty);
            $this->refreshBoardingForDay((int) $record->organization_id, $record->trip_date->toDateString());

            return $record->fresh();
        });
    }

    public function setOnDuty(RegularTrip $trip): RegularTrip
    {
        return DB::transaction(function () use ($trip) {
            $record = $this->lockTrip($trip);

            if ($record->status === RegularTripStatus::OnTrip) {
                throw ValidationException::withMessages([
                    'status' => ['Finish the active trip before returning to the rotation.'],
                ]);
            }

            if ($record->status === RegularTripStatus::Completed) {
                $waiting = $this->waitingOutboundForBoat($record);

                return $waiting ?? $this->openNextCycle($record);
            }

            if ($record->status !== RegularTripStatus::OffDuty && ! $record->status->isWaiting()) {
                throw ValidationException::withMessages([
                    'status' => ['This boat cannot return to the rotation.'],
                ]);
            }

            if ($record->status->isWaiting()) {
                return $record;
            }

            if ($this->boatHasActiveTrip($record, excludeId: $record->id)) {
                throw ValidationException::withMessages([
                    'status' => ['This boat already has an active trip.'],
                ]);
            }

            $record->update([
                'status' => RegularTripStatus::Scheduled,
                'queue_order' => $this->nextWaitingOrder($record),
            ]);

            $this->syncReturnTrip($record->fresh());
            $this->refreshBoardingForDay((int) $record->organization_id, $record->trip_date->toDateString());

            return $record->fresh();
        });
    }

    /**
     * Kept for older callers: departing no longer moves the boat to the last queue slot.
     * Waiting boats are compacted so the next On Duty boat becomes 1st.
     */
    public function cycleLeaveOrderAfterDeparture(RegularTrip $departed): void
    {
        $departed->refresh();

        if ($departed->status === RegularTripStatus::OnTrip) {
            $this->compactWaitingQueue($departed);
        }
    }

    public function completedTripsToday(RegularTrip $trip): int
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->where('status', RegularTripStatus::Completed)
            ->get()
            ->filter(fn (RegularTrip $item) => $item->isOutbound())
            ->count();
    }

    /**
     * @return list<RegularTrip>
     */
    public function boatHistory(RegularTrip $trip): array
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with(['passengers', 'returnTrips.passengers'])
            ->withCount('passengers')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->orderBy('cycle_number')
            ->orderBy('id')
            ->get()
            ->reject(fn (RegularTrip $item) => $item->status === RegularTripStatus::Cancelled)
            ->filter(fn (RegularTrip $item) => $this->visibleInTripList($item))
            ->sortBy(fn (RegularTrip $item) => sprintf(
                '%05d-%d-%010d',
                $item->cycleNumber(),
                $item->isOutbound() ? 0 : 1,
                $item->id,
            ))
            ->values()
            ->all();
    }

    /**
     * A return stays on the day's list once passengers are recorded or the boat has arrived.
     * Blank return slots created ahead of time stay hidden.
     */
    private function visibleInTripList(RegularTrip $item): bool
    {
        if ($item->isOutbound()) {
            return true;
        }

        return $item->arrived_at !== null
            || $item->status === RegularTripStatus::Completed
            || (int) ($item->passengers_count ?? $item->passenger_count) > 0;
    }

    private function lockTrip(RegularTrip $trip): RegularTrip
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->lockForUpdate()
            ->findOrFail($trip->id);
    }

    private function boatHasActiveTrip(RegularTrip $trip, ?int $excludeId = null): bool
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->get();

        return $trips->contains(fn (RegularTrip $item) => $item->status === RegularTripStatus::OnTrip);
    }

    private function nextCycleNumber(RegularTrip $trip): int
    {
        $max = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->max('cycle_number');

        return max(1, (int) $max + 1);
    }

    private function waitingOutboundForBoat(RegularTrip $trip): ?RegularTrip
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->where('boat_id', $trip->boat_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->orderByDesc('id')
            ->get()
            ->first(fn (RegularTrip $item) => $item->isOutbound());
    }

    private function nextWaitingOrder(RegularTrip $trip): int
    {
        $max = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->get()
            ->filter(fn (RegularTrip $item) => $item->isOutbound())
            ->max(fn (RegularTrip $item) => (int) ($item->queue_order ?: $item->rotation_order ?: 0));

        return max(1, (int) $max + 1);
    }

    private function compactWaitingQueue(RegularTrip $trip): void
    {
        // The original rotation number stays fixed for the day.
        // Waiting order is queue_order, so departing must not renumber anyone.
    }

    public function compactOpenWaitingQueues(int $organizationId): void
    {
        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->orderBy('trip_date')
            ->orderBy('rotation_order')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->groupBy(fn (RegularTrip $trip) => $trip->trip_date->toDateString().'|'.$trip->route)
            ->each(function (Collection $group): void {
                $first = $group->first();

                if ($first) {
                    $this->compactWaitingQueue($first);
                }
            });
    }

    public function refreshBoarding(RegularTrip $trip): void
    {
        $trip->refresh();

        $this->notifyBoardingWaitIfDue($trip);
    }

    /**
     * The 30-minute wait starts when a passenger name is typed, not when the boat becomes first.
     */
    public function beginBoarding(RegularTrip $trip): bool
    {
        $trip->refresh();

        if (! $trip->canStartBoarding()) {
            return false;
        }

        return $trip->startBoarding();
    }

    public function refreshBoardingForDay(?int $organizationId, ?string $date = null): void
    {
        if (! $organizationId) {
            return;
        }

        $date ??= now()->toDateString();

        $this->clearEmptyBoardingClocks($organizationId, $date);

        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', $date)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->orderBy('rotation_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->values()
            ->each(function (RegularTrip $trip): void {
                $this->notifyBoardingWaitIfDue($trip);
            });
    }

    private function clearEmptyBoardingClocks(int $organizationId, string $date): void
    {
        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', $date)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->whereNotNull('boarding_started_at')
            ->whereNull('departed_at')
            ->whereDoesntHave('passengers')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->each(function (RegularTrip $trip): void {
                $trip->update([
                    'boarding_started_at' => null,
                    'boarding_wait_notified_at' => null,
                ]);
            });
    }

    public function notifyBoardingWaitIfDue(?RegularTrip $trip = null): int
    {
        $query = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with(['boat.operator.user'])
            ->whereNotNull('boarding_started_at')
            ->whereNull('boarding_wait_notified_at')
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ]);

        if ($trip) {
            $query->whereKey($trip->id);
        }

        $notified = 0;

        $query->get()
            ->filter(fn (RegularTrip $item) => $item->isOutbound() && $item->boardingLimitReached() && ! $item->isAtCapacity())
            ->each(function (RegularTrip $item) use (&$notified): void {
                $item->update(['boarding_wait_notified_at' => now()]);
                $this->notifyBoardingWait($item->fresh(['boat.operator']));
                $notified++;
            });

        return $notified;
    }

    private function notifyBoardingWait(RegularTrip $trip): void
    {
        $recipients = User::query()
            ->where('organization_id', $trip->organization_id)
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->get();

        $operatorUser = $trip->boat?->operator?->user;

        if ($operatorUser && $operatorUser->is_active) {
            $recipients->push($operatorUser);
        }

        $recipients
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new BoardingWaitReachedNotification($trip)));
    }

    private function markPairedReturn(
        RegularTrip $going,
        RegularTripStatus $status,
        bool $completed = false,
        ?int $rotationOrder = null,
    ): void {
        $return = $this->pairedReturn($going);

        if (! $return) {
            return;
        }

        $payload = ['status' => $status];

        if ($completed) {
            $payload['completed_at'] = now();
        }

        if ($rotationOrder !== null) {
            $payload['rotation_order'] = $rotationOrder;
        }

        $return->update($payload);
    }

    private function pairedReturn(RegularTrip $going): ?RegularTrip
    {
        if (! $going->isOutbound()) {
            return null;
        }

        $linked = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('return_of_id', $going->id)
            ->whereNotIn('status', [RegularTripStatus::Cancelled])
            ->orderByDesc('id')
            ->first();

        if ($linked) {
            return $linked;
        }

        $reverse = RegularTrip::reverseDirection($going->direction);

        if (! $reverse || $reverse === $going->direction) {
            return null;
        }

        $candidate = $this->currentPair($going, $reverse);

        if (
            $candidate === null
            || ($candidate->return_of_id && (int) $candidate->return_of_id !== (int) $going->id)
            || $candidate->passengers()->exists()
        ) {
            return null;
        }

        $candidate->update(['return_of_id' => $going->id]);

        return $candidate->fresh();
    }

    private function pairedGoing(RegularTrip $returning): ?RegularTrip
    {
        if ($returning->return_of_id) {
            return RegularTrip::query()
                ->withoutGlobalScope('organization')
                ->find($returning->return_of_id);
        }

        $reverse = RegularTrip::reverseDirection($returning->direction);

        if (! $reverse) {
            return null;
        }

        return $this->currentPair($returning, $reverse);
    }

    private function currentPair(RegularTrip $trip, string $reverseDirection): ?RegularTrip
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->where('direction', $reverseDirection)
            ->where('boat_id', $trip->boat_id)
            ->whereNotIn('status', [
                RegularTripStatus::Completed,
                RegularTripStatus::Cancelled,
            ])
            ->orderByDesc('id')
            ->first();
    }

    private function openNextCycle(RegularTrip $completed): RegularTrip
    {
        $order = (int) $completed->rotation_order > 0
            ? (int) $completed->rotation_order
            : 1;
        $cycle = $this->nextCycleNumber($completed);

        $next = RegularTrip::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $completed->organization_id,
            'boat_id' => $completed->boat_id,
            'route' => $completed->route,
            'direction' => $completed->direction,
            'rotation_order' => $order,
            'queue_order' => $this->nextWaitingOrder($completed),
            'cycle_number' => $cycle,
            'schedule_mode' => RegularTripScheduleMode::Rush,
            'trip_date' => $completed->trip_date->toDateString(),
            'departure_time' => RegularTrip::slotDepartureTime($order),
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'departed_at' => null,
            'arrived_at' => null,
            'completed_at' => null,
            'boarding_started_at' => null,
            'boarding_wait_minutes' => RegularTrip::defaultBoardingWaitMinutes(),
            'boarding_wait_notified_at' => null,
            'remarks' => null,
            'is_auto_generated' => false,
        ]);

        $this->recordHistory($next);
        $this->ensureReturnTrip($next);

        return $next;
    }

    private function placeOffDuty(RegularTrip $completed): RegularTrip
    {
        $existing = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $completed->organization_id)
            ->where('boat_id', $completed->boat_id)
            ->whereDate('trip_date', $completed->trip_date)
            ->where('route', $completed->route)
            ->where('status', RegularTripStatus::OffDuty)
            ->get()
            ->first(fn (RegularTrip $item) => $item->isOutbound());

        if ($existing) {
            return $existing;
        }

        $offDuty = RegularTrip::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $completed->organization_id,
            'boat_id' => $completed->boat_id,
            'route' => $completed->route,
            'direction' => $completed->direction,
            'rotation_order' => (int) $completed->rotation_order > 0 ? (int) $completed->rotation_order : 1,
            'queue_order' => null,
            'cycle_number' => $this->nextCycleNumber($completed),
            'schedule_mode' => RegularTripScheduleMode::Rush,
            'trip_date' => $completed->trip_date->toDateString(),
            'departure_time' => Carbon::parse($completed->departure_time)->format('H:i'),
            'passenger_count' => 0,
            'status' => RegularTripStatus::OffDuty,
            'remarks' => 'off_duty',
            'is_auto_generated' => false,
        ]);

        $this->ensureReturnTrip($offDuty)?->update([
            'status' => RegularTripStatus::OffDuty,
            'remarks' => 'off_duty',
        ]);

        return $offDuty;
    }

    public function setDayScheduleMode(int $organizationId, string $date, RegularTripScheduleMode $mode): int
    {
        $updated = 0;

        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', $date)
            ->get()
            ->each(function (RegularTrip $trip) use ($mode, &$updated): void {
                if (! $trip->isOutbound()) {
                    return;
                }

                $trip->update([
                    'schedule_mode' => $mode,
                    'is_auto_generated' => false,
                ]);
                $updated++;
            });

        $this->syncUpcoming($organizationId, refreshAutoDays: true);

        return $updated;
    }

    public function removeFromRotation(RegularTrip $trip): void
    {
        $organizationId = $trip->organization_id;

        $this->deleteTripAndPair($trip);

        if ($organizationId) {
            $this->compactOpenWaitingQueues($organizationId);
            $this->syncUpcoming($organizationId, refreshAutoDays: true);
        }
    }

    public function dropBoatFromOpenLineup(Boat $boat): int
    {
        return $this->pruneOpenLineupTrips(
            RegularTrip::query()
                ->withoutGlobalScope('organization')
                ->where('boat_id', $boat->id)
                ->whereDate('trip_date', '>=', now()->toDateString())
                ->whereIn('status', [
                    RegularTripStatus::Scheduled,
                    RegularTripStatus::Delayed,
                    RegularTripStatus::OffDuty,
                ])
                ->get(),
        );
    }

    public function pruneInactiveBoatsFromLineup(?int $organizationId = null): int
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with(['boat' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
                RegularTripStatus::OffDuty,
            ])
            ->get()
            ->filter(fn (RegularTrip $trip) => ! $trip->boat?->is_active);

        return $this->pruneOpenLineupTrips($trips);
    }

    public function restoreBoatToOpenLineup(Boat $boat): int
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->where('status', RegularTripStatus::Cancelled)
            ->where('remarks', self::ARCHIVE_HOLD_REMARK)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->values();

        $restored = 0;

        foreach ($trips as $trip) {
            $trip->update([
                'status' => RegularTripStatus::Scheduled,
                'remarks' => null,
                'queue_order' => $this->nextWaitingOrder($trip),
            ]);

            $this->ensureReturnTrip($trip)?->update([
                'status' => RegularTripStatus::Scheduled,
                'remarks' => null,
            ]);

            $restored++;
        }

        if ($boat->organization_id) {
            $this->compactOpenWaitingQueues($boat->organization_id);
            $this->syncUpcoming($boat->organization_id, refreshAutoDays: true);
        }

        return $restored;
    }

    public function releaseBoatForPrivateBooking(Boat $boat, string $date): int
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', $date)
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->get();

        foreach ($trips as $trip) {
            $trip->update([
                'status' => RegularTripStatus::Cancelled,
                'remarks' => $trip->remarks ?: 'Skipped — private booking scheduled.',
            ]);
        }

        return $trips->count();
    }

    public function syncReturnTrip(RegularTrip $trip): ?RegularTrip
    {
        $trip->loadMissing('boat');

        if (! $trip->isOutbound()) {
            return $this->ensureReturnTrip($trip);
        }

        $reverse = RegularTrip::reverseDirection($trip->direction);

        if (! $reverse) {
            return null;
        }

        $return = $this->currentPair($trip, $reverse);

        if ($return) {
            if (! in_array($return->status, [RegularTripStatus::Completed, RegularTripStatus::Cancelled], true)) {
                $return->update([
                    'boat_id' => $trip->boat_id,
                    'rotation_order' => $trip->rotation_order ?: 1,
                    'cycle_number' => $trip->cycleNumber(),
                    'return_of_id' => $trip->id,
                ]);
            }

            return $return->fresh();
        }

        return $this->ensureReturnTrip($trip);
    }

    /**
     * @param  Collection<int, RegularTrip>  $trips
     */
    private function pruneOpenLineupTrips(Collection $trips): int
    {
        $removed = 0;
        $organizationIds = [];

        foreach ($trips->sortBy(fn (RegularTrip $trip) => $trip->isOutbound() ? 0 : 1) as $trip) {
            if (! RegularTrip::query()->withoutGlobalScope('organization')->whereKey($trip->id)->exists()) {
                continue;
            }

            if ($trip->organization_id) {
                $organizationIds[$trip->organization_id] = true;
            }

            $this->holdTripForArchive($trip);
            $removed++;
        }

        foreach (array_keys($organizationIds) as $organizationId) {
            $this->compactOpenWaitingQueues((int) $organizationId);
            $this->syncUpcoming((int) $organizationId, refreshAutoDays: true);
        }

        return $removed;
    }

    private function holdTripForArchive(RegularTrip $trip): void
    {
        if (in_array($trip->status, [
            RegularTripStatus::OnTrip,
            RegularTripStatus::Completed,
        ], true)) {
            return;
        }

        $trip->update([
            'status' => RegularTripStatus::Cancelled,
            'remarks' => self::ARCHIVE_HOLD_REMARK,
        ]);

        $pair = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where(function ($query) use ($trip): void {
                $query->where('return_of_id', $trip->id);

                if ($trip->return_of_id) {
                    $query->orWhereKey($trip->return_of_id);
                }
            })
            ->get();

        foreach ($pair as $item) {
            if (in_array($item->status, [
                RegularTripStatus::OnTrip,
                RegularTripStatus::Completed,
            ], true)) {
                continue;
            }

            $item->update([
                'status' => RegularTripStatus::Cancelled,
                'remarks' => self::ARCHIVE_HOLD_REMARK,
            ]);
        }
    }

    private function deleteTripAndPair(RegularTrip $trip): void
    {
        $reverse = RegularTrip::reverseDirection($trip->direction);

        $pairQuery = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $trip->organization_id)
            ->whereDate('trip_date', $trip->trip_date)
            ->where('route', $trip->route)
            ->where('rotation_order', $trip->rotation_order ?: 1)
            ->where('id', '!=', $trip->id);

        if (is_string($reverse) && $reverse !== $trip->direction) {
            $pairQuery->where('direction', $reverse);
        }

        $pair = $pairQuery->first();

        $trip->delete();
        $pair?->delete();
    }

    private function recordCompletedTrip(RegularTrip $trip): void
    {
        $trip->loadMissing('boat');

        if (! $trip->boat) {
            return;
        }

        $trip->boat->histories()->create([
            'category' => 'trip',
            'title' => 'Completed regular trip',
            'occurred_on' => $trip->trip_date,
            'details' => trim(($trip->direction ?: $trip->route).' · '.$trip->departureTimeLabel(), ' ·'),
        ]);
    }

    public function recordHistory(RegularTrip $trip): void
    {
        $trip->loadMissing('boat');

        if (! $trip->boat) {
            return;
        }

        $time = $trip->departureTimeLabel();

        $trip->boat->histories()->create([
            'category' => 'schedule',
            'title' => ($trip->direction ?: $trip->route).' · '.$time,
            'occurred_on' => $trip->trip_date,
            'details' => $trip->is_auto_generated
                ? 'Automatically assigned to the next regular trip rotation.'
                : 'Assigned to regular trip rotation.',
        ]);
    }

    /**
     * @return Collection<int, RegularTrip>
     */
    private function tripsOn(int $organizationId, Carbon $date): Collection
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with('boat')
            ->where('organization_id', $organizationId)
            ->whereDate('trip_date', $date->toDateString())
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();
    }

    private function clearReplaceableAutoDays(int $organizationId, Carbon $from, Carbon $to): void
    {
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $trips = $this->tripsOn($organizationId, $date);

            if ($trips->isEmpty() || $trips->contains(fn (RegularTrip $trip) => ! $trip->is_auto_generated)) {
                continue;
            }

            RegularTrip::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereDate('trip_date', $date->toDateString())
                ->where('is_auto_generated', true)
                ->delete();
        }
    }

    /**
     * @param  Collection<int, RegularTrip>  $source
     */
    private function createRotatedDay(int $organizationId, Collection $source, Carbon $date): int
    {
        return (int) DB::transaction(function () use ($organizationId, $source, $date) {
            if ($this->tripsOn($organizationId, $date)->isNotEmpty()) {
                return 0;
            }

            $slots = $source->values();
            $outbound = $this->uniqueLineupSlots(
                $slots->filter(fn (RegularTrip $trip) => $trip->isOutbound())->values(),
            );
            $returns = collect();

            if ($outbound->isEmpty()) {
                $outbound = $slots;
                $returns = collect();
            }

            $boatIds = $outbound->pluck('boat_id')->values();
            $sourceDate = Carbon::parse($outbound->first()?->trip_date)->startOfDay();
            $steps = max(1, (int) $sourceDate->diffInDays($date));
            $rotatedBoatIds = $this->rotateIds($boatIds, $steps);
            $created = 0;
            $paired = collect();

            foreach ($outbound as $index => $slot) {
                $boat = Boat::query()
                    ->withoutGlobalScope('organization')
                    ->find($rotatedBoatIds[$index] ?? null);

                if (! $boat || ! $boat->isEligibleForRegularTrip() || $boat->hasCommittedPrivateBookingOn($date)) {
                    continue;
                }

                $trip = $this->createSlotTrip($organizationId, $slot, $boat, $date, $index);
                $paired->push([
                    'order' => $slot->rotation_order ?: ($index + 1),
                    'route' => $slot->route,
                    'boat' => $boat,
                    'trip' => $trip,
                ]);
                $created++;
            }

            foreach ($returns as $index => $slot) {
                $pair = $paired->first(
                    fn (array $row) => $row['order'] === ($slot->rotation_order ?: ($index + 1))
                        && $row['route'] === $slot->route,
                );

                $boat = is_array($pair) ? $pair['boat'] : null;

                if (! $boat || ! $boat->isEligibleForRegularTrip() || $boat->hasCommittedPrivateBookingOn($date)) {
                    continue;
                }

                $this->createSlotTrip($organizationId, $slot, $boat, $date, $index);
                $created++;
            }

            foreach ($paired as $row) {
                $return = $this->ensureReturnTrip($row['trip']);

                if ($return?->wasRecentlyCreated) {
                    $created++;
                }
            }

            return $created;
        });
    }

    private function createSlotTrip(
        int $organizationId,
        RegularTrip $slot,
        Boat $boat,
        Carbon $date,
        int $index,
    ): RegularTrip {
        $template = $this->firstCycleTemplate($slot) ?? $slot;

        $trip = RegularTrip::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $organizationId,
            'boat_id' => $boat->id,
            'route' => $template->route,
            'direction' => $template->direction,
            'rotation_order' => $index + 1,
            'queue_order' => $index + 1,
            'cycle_number' => 1,
            'schedule_mode' => RegularTripScheduleMode::Rush,
            'trip_date' => $date->toDateString(),
            'departure_time' => Carbon::parse($template->departure_time)->format('H:i'),
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'remarks' => null,
            'is_auto_generated' => true,
        ]);

        $this->recordHistory($trip);

        return $trip;
    }

    /**
     * One slot per boat so multiple same-day completed trips do not duplicate tomorrow.
     *
     * @param  Collection<int, RegularTrip>  $outbound
     * @return Collection<int, RegularTrip>
     */
    private function firstCycleTemplate(RegularTrip $slot): ?RegularTrip
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $slot->organization_id)
            ->where('boat_id', $slot->boat_id)
            ->whereDate('trip_date', $slot->trip_date)
            ->where('route', $slot->route)
            ->orderBy('cycle_number')
            ->orderBy('id')
            ->get()
            ->first(fn (RegularTrip $item) => $item->isOutbound());
    }

    private function uniqueLineupSlots(Collection $outbound): Collection
    {
        $priority = [
            RegularTripStatus::Scheduled->value => 0,
            RegularTripStatus::Delayed->value => 0,
            RegularTripStatus::OnTrip->value => 1,
            RegularTripStatus::OffDuty->value => 2,
            RegularTripStatus::Completed->value => 3,
            RegularTripStatus::Cancelled->value => 9,
        ];

        return $outbound
            ->reject(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::Cancelled)
            ->sortBy([
                fn (RegularTrip $trip) => $priority[$trip->status->value] ?? 5,
                fn (RegularTrip $trip) => -1 * $trip->id,
            ])
            ->unique('boat_id')
            ->sortBy(fn (RegularTrip $trip) => (int) ($trip->rotation_order ?: 999))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function rotateIds(Collection $ids, int $steps): Collection
    {
        $count = $ids->count();

        if ($count < 2) {
            return $ids->values();
        }

        $offset = $steps % $count;

        return $ids->slice($offset)->concat($ids->take($offset))->values();
    }
}
