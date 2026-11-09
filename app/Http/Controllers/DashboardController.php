<?php

namespace App\Http\Controllers;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\RegularTripStatus;
use App\Models\Announcement;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\RegularTrip;
use App\Services\BoatStatusSyncService;
use App\Services\RegularTripRotationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(RegularTripRotationService $rotation, BoatStatusSyncService $boatStatus): Response
    {
        $organizationId = auth()->user()?->organization_id;

        if ($organizationId) {
            $rotation->syncUpcoming($organizationId);
            $boatStatus->syncOrganization($organizationId);
        }

        $today = now()->toDateString();

        $regularToday = RegularTrip::query()
            ->with(['boat.operator'])
            ->withCount('passengers')
            ->whereDate('trip_date', $today)
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->orderBy('id')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->boat !== null)
            ->values();

        $bookingsToday = BoatBooking::query()
            ->with(['boat.operator'])
            ->withCount('passengers')
            ->whereDate('booking_date', $today)
            ->orderBy('start_time')
            ->get()
            ->filter(fn (BoatBooking $booking) => $booking->boat !== null)
            ->values();

        $boats = Boat::query()
            ->with('operator')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => $this->summaryStats($boats, $regularToday, $bookingsToday),
            'liveOperations' => $this->liveOperations($regularToday, $bookingsToday),
            'actionRequired' => [
                'pending_rentals' => BoatBooking::query()
                    ->where('status', BoatBookingStatus::Pending)
                    ->count(),
                'payments_for_verification' => BoatBooking::query()
                    ->where('payment_status', BookingPaymentStatus::ForVerification)
                    ->count(),
                'reported_issues' => $boats
                    ->filter(fn (Boat $boat) => $boat->status->isOutOfService())
                    ->count(),
            ],
            'rotation' => $this->rotationSpotlight($regularToday),
            'privateBookings' => $this->privateBookings($bookingsToday),
            'tripAnalytics' => $this->tripAnalytics(),
            'recentActivities' => $this->recentActivities($regularToday),
            'notifications' => $this->notifications($boats, $regularToday, $bookingsToday),
            'weatherAlert' => WeatherSuspensionController::activeTodayAlert(),
            'weatherWatchAlert' => WeatherSuspensionController::activeWatchAlert(),
        ]);
    }

    /**
     * @param  Collection<int, Boat>  $boats
     * @param  Collection<int, RegularTrip>  $regularToday
     * @param  Collection<int, BoatBooking>  $bookingsToday
     * @return array<string, int>
     */
    private function summaryStats(
        Collection $boats,
        Collection $regularToday,
        Collection $bookingsToday,
    ): array {
        $regularActive = $regularToday->reject(
            fn (RegularTrip $trip) => in_array($trip->status, [
                RegularTripStatus::Cancelled,
                RegularTripStatus::OffDuty,
            ], true),
        );

        $rentalsToday = $bookingsToday->reject(
            fn (BoatBooking $booking) => in_array($booking->status, [
                BoatBookingStatus::Cancelled,
                BoatBookingStatus::Rejected,
            ], true),
        );

        $regularPassengers = $regularActive->sum(function (RegularTrip $trip) {
            $recorded = (int) ($trip->passengers_count ?? 0);

            return $recorded > 0 ? $recorded : (int) $trip->passenger_count;
        });

        $rentalPassengers = $rentalsToday->sum(function (BoatBooking $booking) {
            $recorded = (int) ($booking->passengers_count ?? 0);

            return $recorded > 0 ? $recorded : (int) $booking->passenger_count;
        });

        return [
            'total_boats' => $boats->count(),
            'available_boats' => $boats->where('status', BoatStatus::Available)->count(),
            'todays_regular_trips' => $regularActive->count(),
            'completed_regular_trips' => $regularToday
                ->where('status', RegularTripStatus::Completed)
                ->count(),
            'todays_passengers' => (int) $regularPassengers + (int) $rentalPassengers,
            'todays_private_rentals' => $rentalsToday->count(),
            'completed_private_rentals' => $bookingsToday
                ->where('status', BoatBookingStatus::Completed)
                ->count(),
            'pending_rental_requests' => BoatBooking::query()
                ->where('status', BoatBookingStatus::Pending)
                ->count(),
            'payments_for_verification' => BoatBooking::query()
                ->where('payment_status', BookingPaymentStatus::ForVerification)
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, RegularTrip>  $regularToday
     * @param  Collection<int, BoatBooking>  $bookingsToday
     * @return list<array<string, mixed>>
     */
    private function liveOperations(Collection $regularToday, Collection $bookingsToday): array
    {
        $regular = $regularToday
            ->reject(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::OffDuty)
            ->map(function (RegularTrip $trip) {
                [$status, $label] = $this->liveTripStatus($trip);

                return [
                    'id' => 'regular-'.$trip->id,
                    'trip_id' => $trip->id,
                    'source' => 'regular',
                    'boat_name' => $trip->boat->name,
                    'boat_code' => $trip->boat->code,
                    'operator_name' => $trip->boat->operator?->full_name ?: 'Unassigned',
                    'trip_type' => 'Regular',
                    'route' => $trip->isOutbound() ? 'Going' : 'Return',
                    'direction' => $trip->direction ?: $trip->route,
                    'status' => $status,
                    'status_label' => $label,
                    'departure' => $trip->departureTimeLabel(),
                    'passenger_count' => (int) $trip->passenger_count,
                    'href' => '/regular-trips/'.$trip->id,
                    'sort' => $trip->departure_time,
                ];
            });

        $rentals = $bookingsToday
            ->reject(fn (BoatBooking $booking) => $booking->status === BoatBookingStatus::Rejected)
            ->map(function (BoatBooking $booking) {
                [$status, $label] = $this->liveRentalStatus($booking);
                $time = Carbon::parse($booking->start_time);

                return [
                    'id' => 'rental-'.$booking->id,
                    'trip_id' => $booking->id,
                    'source' => 'rental',
                    'boat_name' => $booking->boat->name,
                    'boat_code' => $booking->boat->code,
                    'operator_name' => $booking->boat->operator?->full_name ?: 'Unassigned',
                    'trip_type' => 'Rental',
                    'route' => $booking->destination ?: $booking->package ?: 'Private rental',
                    'direction' => $booking->destination ?: $booking->package,
                    'status' => $status,
                    'status_label' => $label,
                    'departure' => $time->format('g:i A'),
                    'passenger_count' => (int) $booking->passenger_count,
                    'href' => '/bookings/requests/'.$booking->id,
                    'sort' => $time->format('H:i'),
                ];
            });

        return $regular
            ->concat($rentals)
            ->sortBy('sort')
            ->values()
            ->map(fn (array $row, int $index) => [
                ...$row,
                'number' => $index + 1,
            ])
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function liveTripStatus(RegularTrip $trip): array
    {
        return match ($trip->status) {
            RegularTripStatus::OnTrip => ['on_trip', 'On Trip'],
            RegularTripStatus::Completed => ['completed', 'Completed'],
            RegularTripStatus::Cancelled => ['cancelled', 'Cancelled'],
            RegularTripStatus::Delayed => ['waiting', 'Waiting'],
            default => ['scheduled', 'Scheduled'],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function liveRentalStatus(BoatBooking $booking): array
    {
        return match ($booking->status) {
            BoatBookingStatus::Ongoing => ['on_trip', 'On Trip'],
            BoatBookingStatus::Confirmed => ['approved', 'Approved'],
            BoatBookingStatus::Pending, BoatBookingStatus::AwaitingPayment => ['pending', 'Pending'],
            BoatBookingStatus::WeatherHold, BoatBookingStatus::ReschedulePending => ['weather_hold', 'Weather Hold'],
            BoatBookingStatus::Completed => ['completed', 'Completed'],
            BoatBookingStatus::PassengerNoShow => ['passenger_no_show', 'Passenger No-Show'],
            BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected => ['cancelled', 'Cancelled'],
        };
    }

    /**
     * @param  Collection<int, RegularTrip>  $regularToday
     * @return array{current: ?array<string, mixed>, next: ?array<string, mixed>, upcoming: ?array<string, mixed>}
     */
    private function rotationSpotlight(Collection $regularToday): array
    {
        $outbound = $regularToday
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->reject(fn (RegularTrip $trip) => in_array($trip->status, [
                RegularTripStatus::Cancelled,
                RegularTripStatus::OffDuty,
                RegularTripStatus::Completed,
            ], true))
            ->values();

        $current = $regularToday->first(
            fn (RegularTrip $trip) => $trip->status === RegularTripStatus::OnTrip,
        );

        $waiting = $outbound
            ->filter(fn (RegularTrip $trip) => $trip->status->isWaiting())
            ->reject(fn (RegularTrip $trip) => $current && $trip->id === $current->id)
            ->sortBy([
                fn (RegularTrip $trip) => (int) ($trip->rotation_order ?: 999),
                fn (RegularTrip $trip) => $trip->id,
            ])
            ->values();

        return [
            'current' => $current ? $this->rotationCard($current, 'On Trip', 'on_trip') : null,
            'next' => ($waiting[0] ?? null)
                ? $this->rotationCard($waiting[0], 'Waiting', 'waiting')
                : null,
            'upcoming' => ($waiting[1] ?? null)
                ? $this->rotationCard($waiting[1], 'Scheduled', 'scheduled')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rotationCard(RegularTrip $trip, string $statusLabel, string $status): array
    {
        return [
            'id' => $trip->id,
            'boat_name' => $trip->boat->name,
            'boat_code' => $trip->boat->code,
            'route' => $trip->isOutbound() ? 'Going' : 'Return',
            'departure_time' => $trip->scheduledTimeLabel(),
            'status' => $status,
            'status_label' => $statusLabel,
            'href' => '/regular-trips/'.$trip->id,
        ];
    }

    /**
     * @param  Collection<int, BoatBooking>  $bookings
     * @return list<array<string, mixed>>
     */
    private function privateBookings(Collection $bookings): array
    {
        return $bookings
            ->reject(fn (BoatBooking $booking) => $booking->status === BoatBookingStatus::Rejected)
            ->values()
            ->map(function (BoatBooking $booking, int $index) {
                $time = Carbon::parse($booking->start_time);
                [$status, $label] = $this->liveRentalStatus($booking);
                $payment = $booking->paymentStatus();

                return [
                    'id' => $booking->id,
                    'number' => $index + 1,
                    'customer' => $booking->customer_name,
                    'contact_number' => $booking->contact_number,
                    'destination' => $booking->destination ?: $booking->package,
                    'time' => $time->format('g:i A'),
                    'date_label' => $booking->booking_date->format('M d, Y'),
                    'status' => $status,
                    'status_label' => $label,
                    'boat_name' => $booking->boat->name,
                    'boat_code' => $booking->boat->code,
                    'operator_name' => $booking->boat->operator?->full_name ?: 'Unassigned',
                    'passengers' => (int) $booking->passenger_count,
                    'payment_status' => $payment->value,
                    'payment_status_label' => $payment->label(),
                    'href' => '/bookings/requests/'.$booking->id,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tripAnalytics(): array
    {
        $from = now()->startOfMonth()->subMonths(5)->toDateString();
        $to = now()->toDateString();

        $regular = RegularTrip::query()
            ->whereDate('trip_date', '>=', $from)
            ->whereDate('trip_date', '<=', $to)
            ->where('status', '!=', RegularTripStatus::Cancelled->value)
            ->get(['trip_date', 'status']);

        $bookings = BoatBooking::query()
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->whereNotIn('status', [
                BoatBookingStatus::Rejected->value,
                BoatBookingStatus::Cancelled->value,
            ])
            ->get(['booking_date', 'status']);

        $cursor = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();
        $days = [];

        while ($cursor->lte($last)) {
            $date = $cursor->toDateString();

            $days[] = [
                'date' => $date,
                'label' => $cursor->format('M j'),
                'regular' => $regular->filter(
                    fn (RegularTrip $trip) => $trip->trip_date->toDateString() === $date,
                )->count(),
                'rentals' => $bookings->filter(
                    fn (BoatBooking $booking) => $booking->booking_date->toDateString() === $date,
                )->count(),
            ];

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Collection<int, RegularTrip>  $regularToday
     * @return list<array<string, mixed>>
     */
    private function recentActivities(Collection $regularToday): array
    {
        $items = collect();

        foreach ($regularToday as $trip) {
            if ($trip->departed_at) {
                $items->push([
                    'id' => 'departed-'.$trip->id,
                    'text' => $trip->boat->name.' departed for a regular '.($trip->isOutbound() ? 'going' : 'return').' trip.',
                    'at' => $trip->departed_at->toIso8601String(),
                    'tone' => 'info',
                ]);
            }

            if ($trip->completed_at) {
                $items->push([
                    'id' => 'completed-'.$trip->id,
                    'text' => $trip->boat->name.' completed a '.($trip->isOutbound() ? 'going' : 'return').' trip.',
                    'at' => $trip->completed_at->toIso8601String(),
                    'tone' => 'success',
                ]);
            }

            if ($trip->created_at?->isToday()) {
                $items->push([
                    'id' => 'lineup-'.$trip->id,
                    'text' => $trip->boat->name.' was added to the rotation lineup.',
                    'at' => $trip->created_at->toIso8601String(),
                    'tone' => 'info',
                ]);
            }
        }

        BoatBooking::query()
            ->with('boat')
            ->latest()
            ->limit(12)
            ->get()
            ->each(function (BoatBooking $booking) use ($items) {
                $boat = $booking->boat?->name ?: 'A boat';

                if ($booking->status === BoatBookingStatus::Pending) {
                    $items->push([
                        'id' => 'rental-new-'.$booking->id,
                        'text' => 'New rental request submitted by '.$booking->customer_name.'.',
                        'at' => $booking->created_at?->toIso8601String() ?? now()->toIso8601String(),
                        'tone' => 'pending',
                    ]);
                }

                if ($booking->status === BoatBookingStatus::Confirmed) {
                    $items->push([
                        'id' => 'rental-approved-'.$booking->id,
                        'text' => 'Rental request for '.$boat.' was approved.',
                        'at' => $booking->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                        'tone' => 'success',
                    ]);
                }

                if ($booking->status === BoatBookingStatus::Cancelled) {
                    $items->push([
                        'id' => 'rental-cancelled-'.$booking->id,
                        'text' => 'Rental request from '.$booking->customer_name.' was cancelled.',
                        'at' => $booking->cancelled_at?->toIso8601String()
                            ?? $booking->updated_at?->toIso8601String()
                            ?? now()->toIso8601String(),
                        'tone' => 'alert',
                    ]);
                }

                if ($booking->payment_verified_at) {
                    $items->push([
                        'id' => 'payment-'.$booking->id,
                        'text' => 'GCash payment verified for '.$booking->customer_name.'.',
                        'at' => $booking->payment_verified_at->toIso8601String(),
                        'tone' => 'success',
                    ]);
                }
            });

        return $items
            ->unique('id')
            ->filter(fn (array $item) => filled($item['at']))
            ->sortByDesc('at')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Boat>  $boats
     * @param  Collection<int, RegularTrip>  $regularToday
     * @param  Collection<int, BoatBooking>  $bookingsToday
     * @return list<array<string, mixed>>
     */
    private function notifications(
        Collection $boats,
        Collection $regularToday,
        Collection $bookingsToday,
    ): array {
        $items = collect();

        Announcement::query()
            ->latest('published_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->each(function (Announcement $announcement) use ($items) {
                $items->push([
                    'id' => 'announcement-'.$announcement->id,
                    'type' => 'announcement',
                    'type_label' => $announcement->categoryLabel(),
                    'title' => $announcement->title,
                    'detail' => $announcement->body,
                    'href' => '/announcements',
                    'at' => $announcement->published_at?->toIso8601String()
                        ?? $announcement->created_at?->toIso8601String(),
                ]);
            });

        BoatBooking::query()
            ->where('status', BoatBookingStatus::Pending)
            ->latest()
            ->limit(3)
            ->get()
            ->each(function (BoatBooking $booking) use ($items) {
                $items->push([
                    'id' => 'booking-'.$booking->id,
                    'type' => 'booking',
                    'title' => 'New rental request',
                    'detail' => $booking->customer_name.' · '.($booking->destination ?: $booking->package),
                    'href' => '/bookings/requests/'.$booking->id,
                    'at' => $booking->created_at?->toIso8601String(),
                ]);
            });

        $regularBoatIds = $regularToday->pluck('boat_id')->unique();
        $privateBoatIds = $bookingsToday
            ->filter(
                fn (BoatBooking $booking) => in_array($booking->status, [
                    BoatBookingStatus::Confirmed,
                    BoatBookingStatus::Ongoing,
                ], true),
            )
            ->pluck('boat_id')
            ->unique();

        $conflictIds = $regularBoatIds->intersect($privateBoatIds);

        $boats->whereIn('id', $conflictIds)
            ->take(3)
            ->each(function (Boat $boat) use ($items) {
                $items->push([
                    'id' => 'conflict-'.$boat->id,
                    'type' => 'conflict',
                    'title' => 'Booking schedule conflict',
                    'detail' => $boat->name.' has a regular trip and a private booking today',
                    'href' => '/schedules/boat-booking-schedule',
                    'at' => now()->toIso8601String(),
                ]);
            });

        $boats->where('status', BoatStatus::Maintenance)
            ->take(3)
            ->each(function (Boat $boat) use ($items) {
                $items->push([
                    'id' => 'maintenance-'.$boat->id,
                    'type' => 'maintenance',
                    'title' => 'Boat maintenance due',
                    'detail' => $boat->name.' is currently under maintenance',
                    'href' => '/boats',
                    'at' => $boat->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ]);
            });

        return $items
            ->filter(fn (array $item) => filled($item['at']))
            ->unique('id')
            ->take(8)
            ->values()
            ->all();
    }
}
