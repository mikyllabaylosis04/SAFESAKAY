<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Announcement;
use App\Models\BoatBooking;
use App\Models\BookingReschedule;
use App\Models\RegularTrip;
use App\Models\User;
use App\Models\WeatherSuspension;
use App\Notifications\AnnouncementNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class WeatherSuspensionService
{
    /**
     * @param  array{
     *     reason: string,
     *     affected_date: string,
     *     affected_routes: list<string>,
     *     duration_type: string,
     *     starts_at?: string|null,
     *     ends_at?: string|null,
     *     announcement_message: string,
     *     title?: string,
     *     audience?: list<string>
     * }  $data
     */
    public function declare(User $admin, array $data): WeatherSuspension
    {
        $organizationId = (int) $admin->organization_id;
        $date = Carbon::parse($data['affected_date'])->toDateString();
        $routes = array_values(array_unique(array_filter($data['affected_routes'] ?? ['all'])));
        $audience = array_values(array_unique($data['audience'] ?? [
            UserRole::Operator->value,
            UserRole::Passenger->value,
        ]));

        $this->assertNoDuplicate($organizationId, $date, $routes);

        return DB::transaction(function () use ($admin, $organizationId, $date, $routes, $audience, $data) {
            $announcement = Announcement::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $admin->id,
                'title' => $data['title'] ?? ('Trip suspension: '.$this->reasonLabel($data['reason'])),
                'body' => $data['announcement_message'],
                'category' => 'weather',
                'audience' => $audience,
                'published_at' => now(),
                'recipient_count' => 0,
            ]);

            $suspension = WeatherSuspension::query()->create([
                'organization_id' => $organizationId,
                'declared_by' => $admin->id,
                'announcement_id' => $announcement->id,
                'code' => WeatherSuspension::nextCode(),
                'reason' => $data['reason'],
                'affected_date' => $date,
                'affected_routes' => $routes,
                'duration_type' => $data['duration_type'] ?? 'whole_day',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'announcement_message' => $data['announcement_message'],
                'status' => WeatherSuspensionStatus::Active,
            ]);

            $held = $this->holdAffectedRentals($suspension);
            $flagged = $this->flagAffectedTrips($suspension);

            $suspension->update([
                'affected_rentals_count' => $held,
                'affected_trips_count' => $flagged['trips'],
                'review_flagged_count' => $flagged['review'],
            ]);

            $recipients = $this->announcementRecipients($organizationId, $audience);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new AnnouncementNotification($announcement->fresh()));
            }
            $announcement->update(['recipient_count' => $recipients->count()]);

            return $suspension->fresh(['declarer', 'announcement']);
        });
    }

    public function lift(WeatherSuspension $suspension, User $admin, ?string $notes = null): WeatherSuspension
    {
        if (! $suspension->isActive()) {
            throw ValidationException::withMessages([
                'status' => ['This weather suspension is already lifted.'],
            ]);
        }

        $suspension->update([
            'status' => WeatherSuspensionStatus::Lifted,
            'lifted_by' => $admin->id,
            'lifted_at' => now(),
            'lift_notes' => $notes,
        ]);

        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('weather_suspension_id', $suspension->id)
            ->update([
                'weather_suspension_id' => null,
                'weather_review_required' => false,
            ]);

        $announcement = Announcement::query()->create([
            'organization_id' => $suspension->organization_id,
            'user_id' => $admin->id,
            'title' => 'Weather suspension lifted',
            'body' => $notes ?: 'The weather suspension for '.$suspension->affected_date->format('M d, Y').' has been lifted. New trips may proceed after safety clearance. Pending reschedule requests are not auto-approved.',
            'category' => 'resume',
            'audience' => [UserRole::Operator->value, UserRole::Passenger->value],
            'published_at' => now(),
            'recipient_count' => 0,
        ]);

        $recipients = $this->announcementRecipients((int) $suspension->organization_id);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AnnouncementNotification($announcement));
        }
        $announcement->update(['recipient_count' => $recipients->count()]);

        return $suspension->fresh();
    }

    public function extend(WeatherSuspension $suspension, array $data, User $admin): WeatherSuspension
    {
        if (! $suspension->isActive()) {
            throw ValidationException::withMessages([
                'status' => ['Only an active weather suspension can be extended.'],
            ]);
        }

        $suspension->update([
            'duration_type' => $data['duration_type'] ?? $suspension->duration_type,
            'starts_at' => $data['starts_at'] ?? $suspension->starts_at,
            'ends_at' => $data['ends_at'] ?? $suspension->ends_at,
            'announcement_message' => $data['announcement_message'] ?? $suspension->announcement_message,
        ]);

        $held = $this->holdAffectedRentals($suspension->fresh());
        $flagged = $this->flagAffectedTrips($suspension->fresh());

        $suspension->update([
            'affected_rentals_count' => max((int) $suspension->affected_rentals_count, $held),
            'affected_trips_count' => max((int) $suspension->affected_trips_count, $flagged['trips']),
            'review_flagged_count' => max((int) $suspension->review_flagged_count, $flagged['review']),
        ]);

        return $suspension->fresh();
    }

    public function requestReschedule(BoatBooking $booking, User $passenger, string $newDate): BoatBooking
    {
        if ((int) $booking->user_id !== (int) $passenger->id) {
            abort(403);
        }

        if (! $booking->canRequestReschedule()) {
            throw ValidationException::withMessages([
                'booking_date' => ['Only weather-hold rentals can be rescheduled.'],
            ]);
        }

        $date = Carbon::parse($newDate)->toDateString();

        if ($date <= now()->toDateString()) {
            throw ValidationException::withMessages([
                'booking_date' => ['Choose a future rental date.'],
            ]);
        }

        $this->assertDateAvailable($booking, $date);

        $from = $booking->booking_date->toDateString();

        $booking->update([
            'status' => BoatBookingStatus::ReschedulePending,
            'requested_booking_date' => $date,
            'reschedule_status' => 'pending_approval',
            'reschedule_rejection_reason' => null,
        ]);

        BookingReschedule::query()->create([
            'boat_booking_id' => $booking->id,
            'weather_suspension_id' => $booking->weather_suspension_id,
            'from_date' => $from,
            'to_date' => $date,
            'status' => 'requested',
            'requested_by' => $passenger->id,
            'payment_carried_forward' => $booking->paymentStatus()->value === 'verified',
            'down_payment_amount' => $booking->down_payment_amount,
            'payment_status' => $booking->paymentStatus()->value,
        ]);

        return $booking->fresh();
    }

    public function syncReschedulePendingStatus(BoatBooking $booking): void
    {
        if (! $booking->hasOpenWeatherRescheduleRequest()) {
            return;
        }

        if ($booking->status === BoatBookingStatus::ReschedulePending) {
            return;
        }

        $booking->update([
            'status' => BoatBookingStatus::ReschedulePending,
            'reschedule_status' => $booking->reschedule_status ?: 'pending_approval',
        ]);
    }

    public function userCanReviewReschedule(BoatBooking $booking, User $user): bool
    {
        if (! $booking->canReviewReschedule()) {
            return false;
        }

        if ($user->role?->canAccessAdminWeb()) {
            return true;
        }

        if (! $user->isMobileOperator()) {
            return false;
        }

        return \App\Models\Operator::userManagesBoat($user, $booking->boat_id);
    }

    public function approveReschedule(BoatBooking $booking, User $reviewer): BoatBooking
    {
        $this->syncReschedulePendingStatus($booking);
        $booking->refresh();

        if (! $booking->canReviewReschedule()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending reschedule request for this rental.'],
            ]);
        }

        $date = $booking->pendingRequestedBookingDate()?->toDateString();
        if (! $date) {
            throw ValidationException::withMessages([
                'booking_date' => ['The requested date is missing.'],
            ]);
        }

        $this->assertDateAvailable($booking, $date);

        $from = $booking->booking_date->toDateString();

        $booking->update([
            'original_booking_date' => $booking->original_booking_date ?? $booking->booking_date,
            'booking_date' => $date,
            'status' => BoatBookingStatus::Confirmed,
            'reschedule_status' => 'approved',
            'requested_booking_date' => null,
            'weather_review_required' => false,
        ]);

        $open = $booking->reschedules()->where('status', 'requested')->latest('id')->first();
        $open?->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'to_date' => $date,
            'from_date' => $from,
        ]);

        return $booking->fresh();
    }

    public function rejectReschedule(BoatBooking $booking, User $reviewer, string $reason): BoatBooking
    {
        $this->syncReschedulePendingStatus($booking);
        $booking->refresh();

        if (! $booking->canReviewReschedule()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending reschedule request for this rental.'],
            ]);
        }

        $booking->update([
            'status' => BoatBookingStatus::WeatherHold,
            'reschedule_status' => 'rejected',
            'reschedule_rejection_reason' => $reason,
        ]);

        $open = $booking->reschedules()->where('status', 'requested')->latest('id')->first();
        $open?->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $booking->fresh();
    }

    public function departureBlockMessage(int $organizationId, string $date, ?string $route = null, ?string $time = null): ?string
    {
        $hit = $this->activeCovering($organizationId, $date, $route, $time);

        if (! $hit) {
            return null;
        }

        return 'Departures are suspended because of '.$hit->reasonLabel().' on '.$hit->affected_date->format('M d, Y').'.';
    }

    public function rentalBlockMessage(int $organizationId, string $date, ?string $route = null, ?string $time = null): ?string
    {
        $hit = $this->activeCovering($organizationId, $date, $route, $time);

        if (! $hit) {
            return null;
        }

        return 'Private rentals cannot be confirmed for '.$hit->affected_date->format('M d, Y').' because of '.$hit->reasonLabel().'.';
    }

    /** Weather watch (yellow): new rental bookings are temporarily unavailable until the barangay confirms it is safe. */
    public function rentalWatchMessage(int $organizationId, string $date): ?string
    {
        if (! $this->watchCovering($organizationId, $date)) {
            return null;
        }

        return 'Weather Watch — Booking Temporarily Unavailable on '.Carbon::parse($date)->format('l, F j, Y')
            .'. Due to uncertain weather conditions, boat rental bookings for the affected schedule are temporarily unavailable. '
            .'Please select another available schedule or check again once the barangay confirms that travel conditions are safe.';
    }

    public function activeCovering(int $organizationId, string $date, ?string $route = null, ?string $time = null): ?WeatherSuspension
    {
        $this->promoteRollover($organizationId);

        return WeatherSuspension::query()
            ->where('organization_id', $organizationId)
            ->where('status', WeatherSuspensionStatus::Active)
            ->get()
            ->first(fn (WeatherSuspension $item) => $item->covers($date, $route, $time));
    }

    public function watchCovering(int $organizationId, string $date): ?WeatherSuspension
    {
        return WeatherSuspension::query()
            ->where('organization_id', $organizationId)
            ->where('status', WeatherSuspensionStatus::Active)
            ->get()
            ->first(fn (WeatherSuspension $item) => $item->isWatchDate($date) && ! $item->isRedDate($date));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendarMarks(?int $organizationId = null): array
    {
        $this->promoteRollover($organizationId);

        $query = WeatherSuspension::query()->where('status', WeatherSuspensionStatus::Active);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $marks = [];

        foreach ($query->orderBy('affected_date')->get() as $item) {
            $cursor = $item->affected_date->copy()->startOfDay();
            $end = Carbon::parse($item->redEndDate())->startOfDay();

            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();
                $marks[$date] = $this->markPayload($item, $date, 'red');
                $cursor->addDay();
            }

            $watch = $item->watchDate();

            if (! isset($marks[$watch]) || ($marks[$watch]['tone'] ?? null) !== 'red') {
                $marks[$watch] = $this->markPayload($item, $watch, 'yellow');
            }
        }

        ksort($marks);

        return array_values($marks);
    }

    public function promoteRollover(?int $organizationId = null): void
    {
        $today = now('Asia/Manila')->toDateString();
        $query = WeatherSuspension::query()
            ->where('status', WeatherSuspensionStatus::Active)
            ->whereDate('affected_date', '<', $today);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        foreach ($query->get() as $hold) {
            $cursor = $hold->affected_date->copy()->addDay()->startOfDay();
            $end = Carbon::parse($today)->startOfDay();

            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();
                $this->holdAffectedRentals($hold, $date);
                $this->flagAffectedTrips($hold, $date);
                $cursor->addDay();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function markPayload(WeatherSuspension $item, string $date, string $tone): array
    {
        $red = $tone === 'red';

        return [
            'id' => $item->id,
            'code' => $item->code,
            'reason' => $item->reason->value,
            'reason_label' => $item->reasonLabel(),
            'headline' => $red
                ? 'No trips due to '.$item->reasonLabel()
                : 'Weather watch — morning only',
            'title' => $red
                ? 'Trip suspension — '.$item->reasonLabel()
                : 'Weather watch',
            'message' => $red
                ? $item->announcement_message
                : 'Due to uncertain weather conditions, boat rental bookings for the affected schedule are temporarily unavailable. Please select another available schedule or check again once the barangay confirms that travel conditions are safe.',
            'affected_date' => $date,
            'affected_date_label' => Carbon::parse($date)->format('M d, Y'),
            'affected_routes_label' => $item->affectedRoutesLabel(),
            'duration_label' => $red ? $item->durationLabel() : 'Until weather clears',
            'tone' => $tone,
            'can_book' => false,
            'status' => $red ? 'weather_hold' : 'watch',
        ];
    }

    /**
     * @param  list<string>  $routes
     */
    private function assertNoDuplicate(int $organizationId, string $date, array $routes): void
    {
        $existing = WeatherSuspension::query()
            ->where('organization_id', $organizationId)
            ->where('status', WeatherSuspensionStatus::Active)
            ->whereDate('affected_date', $date)
            ->get();

        $incoming = collect($routes)->map(fn ($route) => strtolower((string) $route));

        $duplicate = $existing->first(function (WeatherSuspension $item) use ($incoming) {
            if ($item->coversAllRoutes() || $incoming->contains('all')) {
                return true;
            }

            return collect($item->affected_routes ?? [])
                ->map(fn ($route) => strtolower((string) $route))
                ->intersect($incoming)
                ->isNotEmpty();
        });

        if ($duplicate) {
            throw ValidationException::withMessages([
                'affected_date' => ['An active weather suspension already covers this date and route. Lift or extend it instead of creating a duplicate.'],
            ]);
        }
    }

    private function holdAffectedRentals(WeatherSuspension $suspension, ?string $date = null): int
    {
        $held = 0;
        $onDate = $date ?? $suspension->affected_date->toDateString();

        BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with(['user', 'boat.operator.user'])
            ->where('organization_id', $suspension->organization_id)
            ->whereDate('booking_date', $onDate)
            ->whereNotIn('status', [
                BoatBookingStatus::Completed,
                BoatBookingStatus::PassengerNoShow,
                BoatBookingStatus::Cancelled,
                BoatBookingStatus::Rejected,
            ])
            ->get()
            ->each(function (BoatBooking $booking) use ($suspension, $onDate, &$held): void {
                $route = $booking->destination ?: $booking->package;
                $time = Carbon::parse($booking->start_time)->format('H:i');

                if (! $suspension->covers($onDate, $route, $time)) {
                    return;
                }

                if ($booking->status === BoatBookingStatus::Ongoing) {
                    $booking->update([
                        'weather_suspension_id' => $suspension->id,
                        'weather_reason' => $suspension->reason->value,
                        'weather_review_required' => true,
                    ]);
                    $held++;

                    return;
                }

                if ($booking->status->isWeatherPaused() && (int) $booking->weather_suspension_id === (int) $suspension->id) {
                    return;
                }

                $booking->update([
                    'original_booking_date' => $booking->original_booking_date ?? $booking->booking_date,
                    'status' => BoatBookingStatus::WeatherHold,
                    'weather_suspension_id' => $suspension->id,
                    'weather_hold_at' => now(),
                    'weather_reason' => $suspension->reason->value,
                    'reschedule_status' => 'awaiting_passenger',
                    'weather_review_required' => false,
                ]);
                $held++;
            });

        return $held;
    }

    /**
     * @return array{trips: int, review: int}
     */
    private function flagAffectedTrips(WeatherSuspension $suspension, ?string $date = null): array
    {
        $trips = 0;
        $review = 0;
        $onDate = $date ?? $suspension->affected_date->toDateString();

        RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $suspension->organization_id)
            ->whereDate('trip_date', $onDate)
            ->whereNotIn('status', [
                RegularTripStatus::Completed,
                RegularTripStatus::Cancelled,
            ])
            ->get()
            ->each(function (RegularTrip $trip) use ($suspension, $onDate, &$trips, &$review): void {
                $route = $trip->direction ?: $trip->route;
                $time = $trip->hasScheduledTime()
                    ? Carbon::parse($trip->departure_time)->format('H:i')
                    : null;

                if (! $suspension->covers($onDate, $route, $time)) {
                    return;
                }

                $trips++;

                if ($trip->status === RegularTripStatus::OnTrip) {
                    $trip->update([
                        'weather_suspension_id' => $suspension->id,
                        'weather_review_required' => true,
                    ]);
                    $review++;

                    return;
                }

                $trip->update([
                    'weather_suspension_id' => $suspension->id,
                    'weather_review_required' => false,
                ]);
            });

        return ['trips' => $trips, 'review' => $review];
    }

    private function assertDateAvailable(BoatBooking $booking, string $date): void
    {
        $booking->loadMissing('boat');
        $orgId = (int) ($booking->organization_id ?: $booking->boat?->organization_id);
        $route = $booking->destination ?: $booking->package;
        $time = Carbon::parse($booking->start_time)->format('H:i');

        if ($block = $this->rentalBlockMessage($orgId, $date, $route, $time) ?? $this->rentalWatchMessage($orgId, $date)) {
            throw ValidationException::withMessages([
                'booking_date' => [$block],
            ]);
        }

        $conflict = $booking->boat?->slotConflictMessage($date, $time, $booking->id);
        if ($conflict) {
            throw ValidationException::withMessages([
                'booking_date' => [$conflict],
            ]);
        }
    }

    /**
     * @param  list<string>  $audience
     * @return Collection<int, User>
     */
    private function announcementRecipients(int $organizationId, array $audience = []): Collection
    {
        $roles = collect($audience)
            ->flatMap(function (string $role) {
                $value = UserRole::tryFrom($role);

                if ($value === null) {
                    return [];
                }

                return $value === UserRole::Operator
                    ? [UserRole::Operator, UserRole::Staff]
                    : [$value];
            })
            ->unique()
            ->all();

        if ($roles === []) {
            $roles = [
                UserRole::Operator,
                UserRole::Staff,
                UserRole::Passenger,
                UserRole::User,
            ];
        }

        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', $roles)
            ->get();
    }

    private function reasonLabel(string $reason): string
    {
        return WeatherHoldReason::tryFrom($reason)?->label() ?? 'Bad Weather';
    }
}
