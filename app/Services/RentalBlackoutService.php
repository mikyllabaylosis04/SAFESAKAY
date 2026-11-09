<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\RentalBlackoutReason;
use App\Enums\RentalBlackoutStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\BoatBooking;
use App\Models\RentalBookingBlackout;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RentalBlackoutService
{
    public function covering(int $organizationId, string $date): ?RentalBookingBlackout
    {
        $this->completeExpired($organizationId);
        $onDate = Carbon::parse($date)->toDateString();

        return RentalBookingBlackout::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->blocking()
            ->whereDate('start_date', '<=', $onDate)
            ->whereDate('end_date', '>=', $onDate)
            ->orderBy('start_date')
            ->first();
    }

    public function blockMessage(int $organizationId, string $date): ?string
    {
        $hit = $this->covering($organizationId, $date);

        return $hit?->passengerMessage();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendarMarks(int $organizationId): array
    {
        $this->completeExpired($organizationId);

        $marks = [];

        foreach (
            RentalBookingBlackout::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->blocking()
                ->orderBy('start_date')
                ->get() as $item
        ) {
            $cursor = $item->start_date->copy()->startOfDay();
            $end = $item->end_date->copy()->startOfDay();

            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();
                $marks[$date] = $this->markPayload($item, $date);
                $cursor->addDay();
            }
        }

        ksort($marks);

        return array_values($marks);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminRecords(): array
    {
        $this->completeExpired();

        return RentalBookingBlackout::query()
            ->with(['creator', 'editor'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RentalBookingBlackout $item) => $item->toAdminRecord())
            ->all();
    }

    /**
     * @param  array{
     *     start_date: string,
     *     end_date: string,
     *     reason: string,
     *     remarks?: string|null,
     *     auto_resume?: bool,
     *     acknowledge_existing?: bool
     * }  $data
     */
    public function create(User $admin, array $data): RentalBookingBlackout
    {
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $this->assertRange($start, $end);
        $this->assertNoOverlap((int) $admin->organization_id, $start, $end);
        $this->assertExistingReviewed((int) $admin->organization_id, $start, $end, (bool) ($data['acknowledge_existing'] ?? false));

        return DB::transaction(function () use ($admin, $data, $start, $end) {
            $blackout = RentalBookingBlackout::query()->create([
                'organization_id' => $admin->organization_id,
                'code' => RentalBookingBlackout::nextCode(),
                'start_date' => $start,
                'end_date' => $end,
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'auto_resume' => $data['auto_resume'] ?? true,
                'status' => $this->statusForDates($start, $end),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $announcement = $this->notifyPassengers($admin, $blackout->fresh());
            $blackout->update(['announcement_id' => $announcement->id]);

            return $blackout->fresh(['creator', 'editor']);
        });
    }

    /**
     * @param  array{
     *     start_date: string,
     *     end_date: string,
     *     reason: string,
     *     remarks?: string|null,
     *     auto_resume?: bool,
     *     acknowledge_existing?: bool
     * }  $data
     */
    public function update(RentalBookingBlackout $blackout, User $admin, array $data): RentalBookingBlackout
    {
        if (! in_array($blackout->status, [RentalBlackoutStatus::Scheduled, RentalBlackoutStatus::Active], true)) {
            throw ValidationException::withMessages([
                'status' => ['This suspension schedule can no longer be edited.'],
            ]);
        }

        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $this->assertRange($start, $end);
        $this->assertNoOverlap((int) $admin->organization_id, $start, $end, $blackout->id);
        $this->assertExistingReviewed((int) $admin->organization_id, $start, $end, (bool) ($data['acknowledge_existing'] ?? false));

        $blackout->update([
            'start_date' => $start,
            'end_date' => $end,
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
            'auto_resume' => $data['auto_resume'] ?? true,
            'status' => $this->statusForDates($start, $end),
            'updated_by' => $admin->id,
        ]);

        $this->notifyPassengers($admin, $blackout->fresh(), true);

        return $blackout->fresh(['creator', 'editor']);
    }

    public function cancel(RentalBookingBlackout $blackout, User $admin, ?string $notes = null): RentalBookingBlackout
    {
        if (! in_array($blackout->status, [RentalBlackoutStatus::Scheduled, RentalBlackoutStatus::Active], true)) {
            throw ValidationException::withMessages([
                'status' => ['This suspension schedule is already closed.'],
            ]);
        }

        $blackout->update([
            'status' => RentalBlackoutStatus::Cancelled,
            'cancelled_by' => $admin->id,
            'cancelled_at' => now(),
            'cancel_notes' => $notes,
            'updated_by' => $admin->id,
        ]);

        $announcement = Announcement::query()->create([
            'organization_id' => $blackout->organization_id,
            'user_id' => $admin->id,
            'title' => 'Exclusive rental bookings are open again',
            'body' => $notes ?: 'The scheduled rental booking blackout for '.$blackout->periodLabel().' was cancelled. Exclusive boat rentals are available again.',
            'category' => 'resume',
            'audience' => [UserRole::Passenger->value, UserRole::Operator->value],
            'published_at' => now(),
            'recipient_count' => 0,
        ]);

        $this->sendAnnouncement((int) $blackout->organization_id, $announcement);

        return $blackout->fresh();
    }

    public function completeExpired(?int $organizationId = null): void
    {
        $today = now('Asia/Manila')->toDateString();
        $query = RentalBookingBlackout::query()
            ->withoutGlobalScope('organization')
            ->blocking()
            ->where('auto_resume', true)
            ->whereDate('end_date', '<', $today);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $query->get()->each(function (RentalBookingBlackout $item) {
            $next = $this->statusForDates(
                $item->start_date->toDateString(),
                $item->end_date->toDateString(),
            );

            if ($next !== RentalBlackoutStatus::Completed) {
                return;
            }

            $item->update([
                'status' => RentalBlackoutStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        $activeQuery = RentalBookingBlackout::query()
            ->withoutGlobalScope('organization')
            ->where('status', RentalBlackoutStatus::Scheduled)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);

        if ($organizationId) {
            $activeQuery->where('organization_id', $organizationId);
        }

        $activeQuery->update(['status' => RentalBlackoutStatus::Active->value]);
    }

    /**
     * @return list<array{id: int, code: string, boat_name: string, booking_date_label: string, status_label: string}>
     */
    public function existingRentals(int $organizationId, string $start, string $end): array
    {
        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with('boat')
            ->where('organization_id', $organizationId)
            ->whereDate('booking_date', '>=', $start)
            ->whereDate('booking_date', '<=', $end)
            ->whereIn('status', [
                BoatBookingStatus::Pending,
                BoatBookingStatus::AwaitingPayment,
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
                BoatBookingStatus::WeatherHold,
                BoatBookingStatus::ReschedulePending,
            ])
            ->orderBy('booking_date')
            ->get()
            ->map(fn (BoatBooking $booking) => [
                'id' => $booking->id,
                'code' => $booking->code,
                'boat_name' => $booking->boat?->name ?? 'Boat',
                'booking_date_label' => $booking->booking_date->format('M d, Y'),
                'status_label' => $booking->status->label(),
            ])
            ->all();
    }

    private function assertRange(string $start, string $end): void
    {
        if ($end < $start) {
            throw ValidationException::withMessages([
                'end_date' => ['End date must be on or after the start date.'],
            ]);
        }
    }

    private function assertNoOverlap(int $organizationId, string $start, string $end, ?int $exceptId = null): void
    {
        $overlap = RentalBookingBlackout::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->blocking()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => ['This period overlaps an existing rental blackout schedule.'],
            ]);
        }
    }

    private function assertExistingReviewed(int $organizationId, string $start, string $end, bool $acknowledged): void
    {
        $existing = $this->existingRentals($organizationId, $start, $end);

        if ($existing === [] || $acknowledged) {
            return;
        }

        $codes = collect($existing)->pluck('code')->join(', ');

        throw ValidationException::withMessages([
            'acknowledge_existing' => [
                'Existing exclusive rentals fall in this period ('.$codes.'). Review them in Rental Request. They will not be cancelled automatically. Confirm to save the blackout.',
            ],
        ]);
    }

    private function statusForDates(string $start, string $end): RentalBlackoutStatus
    {
        $today = now('Asia/Manila')->toDateString();

        if ($end < $today) {
            return RentalBlackoutStatus::Completed;
        }

        if ($start <= $today) {
            return RentalBlackoutStatus::Active;
        }

        return RentalBlackoutStatus::Scheduled;
    }

    private function notifyPassengers(User $admin, RentalBookingBlackout $blackout, bool $updated = false): Announcement
    {
        $reason = $blackout->reason instanceof RentalBlackoutReason
            ? $blackout->reason->label()
            : 'Special Event';
        $title = $updated
            ? 'Exclusive rental blackout dates updated'
            : 'Exclusive rental bookings unavailable ('.$reason.')';
        $body = 'Exclusive boat rental bookings are temporarily unavailable from '.$blackout->periodLabel()
            .' due to '.$blackout->reason->phrase()
            .'. All boats will prioritize regular passenger trips during this period.'
            .($blackout->remarks ? ' '.$blackout->remarks : '');

        $announcement = Announcement::query()->create([
            'organization_id' => $blackout->organization_id,
            'user_id' => $admin->id,
            'title' => $title,
            'body' => $body,
            'category' => 'general',
            'audience' => [UserRole::Passenger->value, UserRole::Operator->value],
            'published_at' => now(),
            'recipient_count' => 0,
        ]);

        $this->sendAnnouncement((int) $blackout->organization_id, $announcement);

        return $announcement;
    }

    private function sendAnnouncement(int $organizationId, Announcement $announcement): void
    {
        $recipients = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Passenger, UserRole::Operator, UserRole::Staff, UserRole::User])
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AnnouncementNotification($announcement));
        }

        $announcement->update(['recipient_count' => $recipients->count()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function markPayload(RentalBookingBlackout $item, string $date): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'reason' => $item->reason->value,
            'reason_label' => $item->reason->label(),
            'headline' => 'Exclusive Rental Booking Unavailable',
            'title' => 'Exclusive Rental Booking Unavailable',
            'message' => $item->passengerMessage(),
            'affected_date' => $date,
            'affected_date_label' => Carbon::parse($date)->format('M d, Y'),
            'affected_routes_label' => 'Exclusive rentals only',
            'duration_label' => $item->periodLabel(),
            'tone' => 'red',
            'can_book' => false,
            'kind' => 'rental_blackout',
            'type' => 'rental_blackout',
            'status' => 'no_sail',
        ];
    }
}
