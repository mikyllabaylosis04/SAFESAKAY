<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BoatHistory;
use App\Models\RegularTrip;

class BoatStatusSyncService
{
    public function applyManagedStatus(Boat $boat, BoatStatus $status): Boat
    {
        $previous = $boat->status;

        $boat->update(['status' => $status]);

        if ($previous !== $status) {
            $this->recordStatusHistory($boat, $status);
        }

        if ($status->isOutOfService()) {
            $this->releaseWaitingTrips($boat);

            return $boat->fresh();
        }

        if ($status === BoatStatus::Available) {
            return $this->sync($boat->fresh());
        }

        return $boat->fresh();
    }

    public function sync(Boat $boat): Boat
    {
        if (! $boat->is_active || $boat->status->isOutOfService()) {
            return $boat;
        }

        $next = $this->derivedStatus($boat);

        if ($boat->status !== $next) {
            $boat->update(['status' => $next]);
        }

        return $boat;
    }

    public function syncOrganization(?int $organizationId): void
    {
        Boat::query()
            ->when(
                $organizationId,
                fn ($query) => $query->where('organization_id', $organizationId),
            )
            ->get()
            ->each(fn (Boat $boat) => $this->sync($boat));
    }

    private function derivedStatus(Boat $boat): BoatStatus
    {
        $today = now('Asia/Manila')->toDateString();

        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', $today)
            ->get();

        if ($trips->contains(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::OnTrip)) {
            return BoatStatus::OnTrip;
        }

        $bookings = BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $boat->id)
            ->whereDate('booking_date', $today)
            ->whereIn('status', [
                BoatBookingStatus::AwaitingPayment,
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
            ])
            ->get();

        if ($bookings->contains(fn (BoatBooking $booking) => $booking->status === BoatBookingStatus::Ongoing)) {
            return BoatStatus::OnTrip;
        }

        $onDuty = $trips->contains(
            fn (RegularTrip $trip) => in_array($trip->status, [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ], true),
        ) || $bookings->isNotEmpty();

        return $onDuty ? BoatStatus::OnDuty : BoatStatus::Available;
    }

    private function recordStatusHistory(Boat $boat, BoatStatus $status): void
    {
        $entry = match ($status) {
            BoatStatus::Maintenance => [
                'category' => 'maintenance',
                'title' => 'Marked under maintenance',
                'details' => 'Boat cannot be assigned until it is restored to Available.',
            ],
            BoatStatus::Unavailable => [
                'category' => 'status',
                'title' => 'Marked unavailable',
                'details' => 'Boat cannot be assigned until it is restored to Available.',
            ],
            BoatStatus::Available => [
                'category' => 'status',
                'title' => 'Restored to available',
                'details' => 'Boat is eligible for assignment again.',
            ],
            default => null,
        };

        if ($entry === null) {
            return;
        }

        BoatHistory::query()->create([
            'boat_id' => $boat->id,
            'category' => $entry['category'],
            'title' => $entry['title'],
            'occurred_on' => now()->toDateString(),
            'details' => $entry['details'],
        ]);
    }

    private function releaseWaitingTrips(Boat $boat): void
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', now()->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::Delayed,
            ])
            ->get();

        $rotation = app(RegularTripRotationService::class);

        foreach ($trips as $trip) {
            if (! $trip->isOutbound()) {
                continue;
            }

            $rotation->setOffDuty($trip);
        }
    }
}
