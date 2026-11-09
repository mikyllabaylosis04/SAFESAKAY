<?php

namespace App\Http\Controllers;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * @var Collection<int, OrganizationRoute>|null
     */
    private ?Collection $fareRoutes = null;

    public function index(Request $request): Response
    {
        $canViewPassengerDetails = $this->canViewPassengerDetails();
        $tripFilters = $this->tripFilters($request);
        $regularTrips = $this->regularTripRecords($canViewPassengerDetails);

        $bookings = $this->bookingRecords($canViewPassengerDetails);

        $organizationId = (int) $request->user()?->organization_id;

        return Inertia::render('reports/Index', [
            'refundRecords' => \App\Support\ReportInsights::refunds($organizationId),
            'payoutRecords' => \App\Support\ReportInsights::payouts($organizationId),
            'incidentRecords' => \App\Support\ReportInsights::incidents($organizationId),
            'operatorActivity' => \App\Support\ReportInsights::operatorActivity($organizationId),
            'regularTrips' => $regularTrips,
            'filteredRegularTrips' => $this->filterRegularTripRecords($regularTrips, $tripFilters),
            'tripFilters' => $tripFilters,
            'reportBoats' => Boat::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Boat $boat) => [
                    'id' => $boat->id,
                    'name' => $boat->name,
                ])
                ->values(),
            'bookings' => $bookings,
            'rentalPayments' => $this->rentalPaymentRows($organizationId),
            'weatherSuspensions' => \App\Http\Controllers\WeatherSuspensionController::records(),
            'revenueRecords' => $this->revenueRecords($regularTrips, $bookings, $canViewPassengerDetails),
            'operators' => $this->operatorRecords((int) $request->user()?->organization_id),
            'boatUsage' => $this->boatUsage($regularTrips, $bookings),
            'firstAssignedCounts' => $this->firstAssignedCounts(),
            'canViewPassengerDetails' => $canViewPassengerDetails,
            'manifestBoats' => $regularTrips
                ->map(fn (array $trip) => [
                    'id' => $trip['boat']['id'],
                    'name' => $trip['boat']['name'],
                ])
                ->unique('id')
                ->sortBy('name')
                ->values(),
            'manifestRoutes' => $regularTrips
                ->pluck('direction')
                ->filter()
                ->unique()
                ->sort()
                ->values(),
            'manifestStatuses' => collect([
                RegularTripStatus::Scheduled,
                RegularTripStatus::OnTrip,
                RegularTripStatus::Completed,
            ])->map(fn (RegularTripStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->values(),
        ]);
    }

    public function commissions(): RedirectResponse
    {
        return redirect()->route('reports.index');
    }

    public function regularTrips(): RedirectResponse
    {
        return redirect()->route('reports.index');
    }

    public function exportBoats(Request $request): StreamedResponse
    {
        $canViewPassengerDetails = $this->canViewPassengerDetails();
        $regularTrips = $this->regularTripRecords($canViewPassengerDetails);
        $bookings = $this->bookingRecords($canViewPassengerDetails);
        $rows = collect($this->boatUsage($regularTrips, $bookings));

        $status = (string) $request->query('status', 'all');
        $operator = (string) $request->query('operator', 'all');
        $search = strtolower(trim((string) $request->query('search', '')));
        $boatId = $request->query('boat_id');

        $filtered = $rows->filter(function (array $boat) use ($status, $operator, $search, $boatId): bool {
            if ($boatId !== null && $boatId !== '' && (string) $boat['id'] !== (string) $boatId) {
                return false;
            }

            $reportStatus = $boat['report_status'] ?? 'active';

            if ($status !== 'all' && $reportStatus !== $status) {
                return false;
            }

            if ($operator !== 'all' && ($boat['operator_name'] ?? '') !== $operator) {
                return false;
            }

            if ($search !== '') {
                $haystack = strtolower(trim(
                    ($boat['name'] ?? '').' '.($boat['operator_name'] ?? '').' '.($boat['code'] ?? ''),
                ));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        })->values();

        $filename = filled($boatId)
            ? 'boat-report-'.now()->format('Y-m-d').'.csv'
            : 'registered-boats-'.now()->format('Y-m-d').'.csv';

        $headers = [
            'Boat Name',
            'Operator',
            'Capacity',
            'Status',
            'Regular Trips',
            'Rental Trips',
            'Completed Trips',
            'Last Maintenance',
            'Total Revenue',
        ];

        return response()->streamDownload(function () use ($filtered, $headers): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $headers);

            foreach ($filtered as $boat) {
                fputcsv($handle, [
                    $boat['name'] ?? '',
                    $boat['operator_name'] ?? 'Unassigned',
                    $boat['capacity'] ?? '',
                    $boat['report_status_label'] ?? $boat['status_label'] ?? '',
                    $boat['regular_trips'] ?? 0,
                    $boat['private_bookings'] ?? 0,
                    $boat['completed_trips'] ?? 0,
                    $boat['last_maintenance_label'] ?? '—',
                    $boat['total_revenue_label'] ?? OrganizationRoute::formatPeso((float) ($boat['total_revenue'] ?? 0)),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function printManifest(RegularTrip $regularTrip): HttpResponse
    {
        return $this->manifestDocument($regularTrip);
    }

    public function downloadManifest(RegularTrip $regularTrip): HttpResponse
    {
        request()->merge(['print' => true]);

        return $this->manifestDocument($regularTrip);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $trips
     * @param  Collection<int, array<string, mixed>>  $bookings
     * @return list<array<string, mixed>>
     */
    private function boatUsage(Collection $trips, Collection $bookings): array
    {
        $boats = Boat::query()
            ->with(['operator', 'histories', 'pictures'])
            ->orderBy('name')
            ->get();

        $tripsByBoat = $trips->groupBy(fn (array $trip) => (string) $trip['boat']['id']);
        $bookingsByBoat = $bookings->groupBy(fn (array $booking) => (string) $booking['boat']['id']);

        $fleetTotal = max(1, $boats->sum(function (Boat $boat) use ($tripsByBoat, $bookingsByBoat) {
            $id = (string) $boat->id;

            return $tripsByBoat->get($id, collect())->count()
                + $bookingsByBoat->get($id, collect())->reject(
                    fn (array $booking) => in_array($booking['status'] ?? '', ['pending', 'rejected', 'cancelled'], true),
                )->count();
        }));

        return $boats->map(function (Boat $boat) use ($fleetTotal, $tripsByBoat, $bookingsByBoat) {
            $id = (string) $boat->id;
            $boatTrips = $tripsByBoat->get($id, collect());
            $boatBookings = $bookingsByBoat->get($id, collect());
            $activeRentals = $boatBookings->reject(
                fn (array $booking) => in_array($booking['status'] ?? '', ['pending', 'rejected', 'cancelled'], true),
            );
            $regularCount = $boatTrips->count();
            $privateCount = $activeRentals->count();
            $total = $regularCount + $privateCount;
            $regularRevenue = $boatTrips
                ->reject(fn (array $trip) => ($trip['status'] ?? '') === 'cancelled')
                ->sum(fn (array $trip) => (float) ($trip['revenue'] ?? 0));
            $rentalRevenue = $boatBookings
                ->reject(fn (array $booking) => in_array($booking['status'] ?? '', ['cancelled', 'rejected'], true))
                ->sum(fn (array $booking) => (float) ($booking['amount'] ?? 0));
            $completed = $boatTrips->where('status', 'completed')->count()
                + $boatBookings->where('status', 'completed')->count();
            $passengers = $boatTrips->sum(fn (array $trip) => (int) ($trip['passenger_count'] ?? 0))
                + $boatBookings->sum(fn (array $booking) => (int) ($booking['passenger_count'] ?? 0));
            $share = round(($total / $fleetTotal) * 100);
            $maintenance = $boat->histories
                ->where('category', 'maintenance')
                ->sortByDesc(fn ($history) => $history->occurred_on?->toDateString() ?? '')
                ->values();
            $lastMaintenance = $maintenance->first();
            $isMaintenance = $boat->status === BoatStatus::Maintenance;
            $isUnavailable = $boat->status === BoatStatus::Unavailable;

            return [
                'id' => $boat->id,
                'name' => $boat->name,
                'code' => $boat->code,
                'operator_name' => $boat->operator?->full_name,
                'capacity' => (int) $boat->capacity,
                'type' => $boat->type ?: 'Pump Boat',
                'status' => $boat->status->value,
                'status_label' => $boat->status->label(),
                'report_status' => $isMaintenance ? 'maintenance' : ($isUnavailable ? 'unavailable' : 'active'),
                'report_status_label' => $isMaintenance ? 'Under Maintenance' : ($isUnavailable ? 'Unavailable' : 'Active'),
                'picture_url' => $boat->pictureUrl(),
                'registration_date_label' => $boat->registration_date?->format('M d, Y'),
                'registered_in_safesakay_label' => $boat->created_at?->format('M d, Y'),
                'last_maintenance_label' => $lastMaintenance?->occurred_on?->format('M d, Y'),
                'is_active' => (bool) $boat->is_active,
                'regular_trips' => $regularCount,
                'private_bookings' => $privateCount,
                'completed_trips' => $completed,
                'total_passengers' => $passengers,
                'regular_revenue' => round($regularRevenue, 2),
                'rental_revenue' => round($rentalRevenue, 2),
                'total_revenue' => round($regularRevenue + $rentalRevenue, 2),
                'total_revenue_label' => OrganizationRoute::formatPeso($regularRevenue + $rentalRevenue),
                'total_trips' => $total,
                'maintenance_records' => $maintenance->count(),
                'in_maintenance' => $isMaintenance,
                'usage_share' => $share,
                'usage_frequency' => match (true) {
                    $share >= 25 => 'High',
                    $share >= 10 => 'Medium',
                    default => 'Low',
                },
                'maintenance' => $maintenance->map(fn ($history) => [
                    'id' => $history->id,
                    'date' => $history->occurred_on?->toDateString(),
                    'date_label' => $history->occurred_on?->format('M d, Y'),
                    'type' => $history->title,
                    'description' => $history->details,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, int>
     */
    private function firstAssignedCounts(): array
    {
        $trips = RegularTrip::query()
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get(['boat_id', 'trip_date', 'departure_time']);

        $firstByDate = $trips
            ->groupBy(fn (RegularTrip $trip) => $trip->trip_date->toDateString())
            ->map(function ($dayTrips) {
                return $dayTrips->sortBy(fn (RegularTrip $trip) => (string) $trip->departure_time)->first()?->boat_id;
            })
            ->filter();

        return $firstByDate
            ->countBy()
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $trips
     * @param  Collection<int, array<string, mixed>>  $bookings
     * @return list<array<string, mixed>>
     */
    private function revenueRecords(Collection $trips, Collection $bookings, bool $canViewPassengerDetails): array
    {
        $tripRows = $trips->flatMap(function (array $trip) use ($canViewPassengerDetails) {
            $cancelled = ($trip['status'] ?? '') === 'cancelled';
            $status = $this->revenueStatus((string) ($trip['report_status'] ?? $trip['status'] ?? 'scheduled'));
            $base = [
                'date' => $trip['trip_date'],
                'date_time_label' => ($trip['trip_date_label'] ?? '')."\n".($trip['departure_time_label'] ?? ''),
                'type' => 'regular',
                'type_label' => 'Regular Trip',
                'boat_id' => $trip['boat']['id'],
                'boat_name' => $trip['boat']['name'],
                'method' => 'Cash',
                'method_key' => 'cash',
                'status' => $status,
                'status_label' => $trip['report_status_label'] ?? $trip['status_label'] ?? 'Scheduled',
            ];

            $passengers = $canViewPassengerDetails ? ($trip['passengers'] ?? []) : [];

            if ($passengers !== []) {
                return collect($passengers)->map(function (array $passenger, int $index) use ($trip, $cancelled, $base) {
                    $fare = (float) ($passenger['fare_amount'] ?? 0);

                    return [
                        ...$base,
                        'id' => 'trip-'.$trip['id'].'-'.($passenger['id'] ?? $index),
                        'reference' => $trip['code'],
                        'customer' => $passenger['full_name'] ?? '—',
                        'amount' => $fare,
                        'amount_label' => $passenger['fare_amount_label'] ?? OrganizationRoute::formatPeso($fare),
                        'counted_amount' => $cancelled ? 0 : $fare,
                    ];
                })->values()->all();
            }

            $amount = $cancelled ? 0 : (float) ($trip['revenue'] ?? 0);

            return [[
                ...$base,
                'id' => 'trip-'.$trip['id'],
                'reference' => $trip['code'],
                'customer' => $canViewPassengerDetails ? 'No passengers recorded' : '—',
                'amount' => $amount,
                'amount_label' => $trip['revenue_label'] ?? OrganizationRoute::formatPeso($amount),
                'counted_amount' => $amount,
            ]];
        });

        $rentalRows = $bookings->map(function (array $booking) {
            $status = $this->revenueStatus((string) ($booking['report_status'] ?? $booking['status'] ?? 'pending'));
            $cancelled = in_array($status, ['cancelled'], true);
            $confirmed = (float) ($booking['amount'] ?? $booking['verified_amount'] ?? 0);
            $quoted = (float) ($booking['quoted_amount'] ?? 0);
            $display = $confirmed > 0 ? $confirmed : $quoted;
            $method = filled($booking['payment_method'] ?? null) && ($booking['payment_method'] ?? '') !== '—'
                ? (string) $booking['payment_method']
                : 'Cash';

            return [
                'id' => 'rental-'.$booking['id'],
                'date' => $booking['booking_date'],
                'date_time_label' => ($booking['booking_date_label'] ?? '')."\n".($booking['start_time_label'] ?? ''),
                'type' => 'rental',
                'type_label' => 'Private Rental',
                'reference' => $booking['code'],
                'boat_id' => $booking['boat']['id'],
                'boat_name' => $booking['boat']['name'],
                'customer' => $booking['customer_name'] ?? '—',
                'amount' => $display,
                'amount_label' => $confirmed > 0
                    ? ($booking['amount_label'] ?? OrganizationRoute::formatPeso($confirmed))
                    : ($booking['quoted_amount_label'] ?? OrganizationRoute::formatPeso($display)),
                'counted_amount' => $cancelled ? 0 : $confirmed,
                'method' => $method,
                'method_key' => $this->paymentMethodKey($method),
                'status' => $status,
                'status_label' => $booking['report_status_label'] ?? $booking['status_label'] ?? 'Pending',
            ];
        });

        return $tripRows
            ->concat($rentalRows)
            ->sortBy([
                ['date', 'asc'],
                ['reference', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function revenueStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'approved', 'confirmed' => 'approved',
            'pending', 'for_verification', 'unpaid', 'waiting' => 'pending',
            'on_trip', 'ongoing' => 'on_trip',
            'cancelled', 'rejected' => 'cancelled',
            default => 'scheduled',
        };
    }

    private function paymentMethodKey(string $method): string
    {
        $text = strtolower($method);

        return match (true) {
            str_contains($text, 'gcash') => 'gcash',
            str_contains($text, 'bank') => 'bank',
            str_contains($text, 'cash') => 'cash',
            default => 'other',
        };
    }

    /**
     * @return array{from: string, to: string, boat: string, type: string, status: string}
     */
    private function tripFilters(Request $request): array
    {
        $from = $this->validDate($request->query('from'))
            ?? now()->copy()->startOfMonth()->toDateString();
        $to = $this->validDate($request->query('to'))
            ?? now()->copy()->endOfMonth()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $type = (string) $request->query('type', 'all');
        $status = (string) $request->query('status', 'all');
        $boat = (string) $request->query('boat', 'all');

        return [
            'from' => $from,
            'to' => $to,
            'boat' => $boat === 'all' || ! ctype_digit($boat) ? 'all' : $boat,
            'type' => in_array($type, ['all', 'going', 'return'], true) ? $type : 'all',
            'status' => in_array($status, ['all', 'scheduled', 'waiting', 'on_trip', 'completed', 'cancelled'], true)
                ? $status
                : 'all',
        ];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $trips
     * @param  array{from: string, to: string, boat: string, type: string, status: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRegularTripRecords(Collection $trips, array $filters): Collection
    {
        return $trips
            ->filter(function (array $trip) use ($filters) {
                if (($trip['status'] ?? '') === 'off_duty') {
                    return false;
                }

                $date = (string) ($trip['trip_date'] ?? '');

                if ($date < $filters['from'] || $date > $filters['to']) {
                    return false;
                }

                if ($filters['boat'] !== 'all' && (string) ($trip['boat']['id'] ?? '') !== $filters['boat']) {
                    return false;
                }

                if ($filters['type'] === 'going' && ($trip['is_outbound'] ?? true) === false) {
                    return false;
                }

                if ($filters['type'] === 'return' && ($trip['is_outbound'] ?? true) !== false) {
                    return false;
                }

                $status = $trip['report_status'] ?? $trip['status'] ?? '';

                return $filters['status'] === 'all' || $status === $filters['status'];
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function regularTripRecords(bool $canViewPassengerDetails): Collection
    {
        $trips = RegularTrip::query()
            ->with(['boat.operator', 'passengers'])
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->orderBy('id')
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->boat !== null)
            ->values();

        $returns = $trips->filter(fn (RegularTrip $trip) => ! $trip->isOutbound());

        $records = $trips
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->map(function (RegularTrip $trip) use ($returns) {
                $return = $returns->first(function (RegularTrip $item) use ($trip) {
                    if ($item->status === RegularTripStatus::Cancelled) {
                        return false;
                    }

                    if ((int) $item->return_of_id === (int) $trip->id) {
                        return true;
                    }

                    return $item->return_of_id === null
                        && (int) $item->boat_id === (int) $trip->boat_id
                        && $item->route === $trip->route
                        && (int) $item->cycle_number === (int) $trip->cycle_number
                        && $item->trip_date->toDateString() === $trip->trip_date->toDateString();
                });

                return $this->asRoundTripRecord($trip, $return);
            })
            ->filter()
            ->values();

        $codes = [];
        $records->groupBy(fn (RegularTrip $trip) => $trip->trip_date->toDateString())
            ->each(function ($dayTrips, string $date) use (&$codes): void {
                $sequence = 1;
                foreach ($dayTrips as $trip) {
                    $codes[$trip->id] = sprintf('RT-%s-%03d', Carbon::parse($date)->format('Ymd'), $sequence);
                    $sequence++;
                }
            });

        return $records
            ->map(fn (RegularTrip $trip) => $this->transformRegularTrip(
                $trip,
                $canViewPassengerDetails,
                $codes[$trip->id] ?? null,
            ))
            ->values();
    }

    private function asRoundTripRecord(RegularTrip $going, ?RegularTrip $return): RegularTrip
    {
        if (! $return) {
            return $going;
        }

        $going->setRelation(
            'passengers',
            $going->passengers->concat($return->passengers)->values(),
        );
        $going->passenger_count = $going->passengers->count();

        if ($return->arrived_at && $going->arrived_at === null) {
            $going->arrived_at = $return->arrived_at;
        }

        if ($return->status === RegularTripStatus::Completed && $going->status !== RegularTripStatus::Cancelled) {
            $going->status = RegularTripStatus::Completed;
        }

        return $going;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRegularTrip(RegularTrip $trip, bool $canViewPassengerDetails = false, ?string $displayCode = null): array
    {
        $time = Carbon::parse($trip->departure_time);
        $passengers = $trip->manifestPassengers();
        $capacity = max(0, (int) $trip->boat->capacity);
        $count = min($passengers->count(), $capacity);
        $reportStatus = $this->reportStatus($trip->status);

        return [
            'id' => $trip->id,
            'code' => $displayCode ?: sprintf('RT-%s-%03d', $trip->trip_date->format('Ymd'), $trip->id),
            'route' => $trip->route,
            'direction' => $trip->routeLabel(),
            'trip_date' => $trip->trip_date->toDateString(),
            'trip_date_label' => $trip->trip_date->format('M j, Y'),
            'has_departure_time' => $trip->hasScheduledTime(),
            'cycle_number' => $trip->cycleNumber(),
            'trip_number_label' => $trip->tripNumberLabel(),
            'return_of_id' => $trip->return_of_id,
            'scheduled_time_label' => $trip->scheduledTimeLabel(),
            'departure_time' => $trip->hasScheduledTime() ? $time->format('H:i') : '',
            'departure_time_label' => $trip->actualDepartureLabel(),
            'actual_departure_label' => $trip->actualDepartureLabel(),
            'actual_arrival_label' => $trip->actualArrivalLabel(),
            'boarding_started_at_label' => $trip->clockLabel($trip->boarding_started_at),
            'waiting_duration_label' => $trip->waitingDurationLabel(),
            'passenger_count' => $count,
            'is_outbound' => true,
            'leg_label' => 'Regular',
            'trip_type_detail' => $this->tripTypeDetail($trip),
            'status' => $trip->status->value,
            'status_label' => $trip->status->label(),
            'report_status' => $reportStatus['value'],
            'report_status_label' => $reportStatus['label'],
            'remarks' => $trip->remarks,
            ...$this->tripRevenue($trip),
            'passengers' => $canViewPassengerDetails
                ? $passengers->take($count)->map(function (TripPassenger $passenger) use ($trip) {
                    $record = $passenger->toRecord();
                    $amount = $this->fareRouteFor($trip)?->fareAmountFor($passenger->fareType());
                    $record['fare_amount'] = $amount;
                    $record['fare_amount_label'] = $amount === null
                        ? '—'
                        : OrganizationRoute::formatPeso($amount);

                    return $record;
                })->values()->all()
                : [],
            'boat' => [
                'id' => $trip->boat->id,
                'name' => $trip->boat->name,
                'code' => $trip->boat->code,
                'capacity' => $capacity,
                'operator_name' => $trip->boat->operator?->full_name,
            ],
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function reportStatus(RegularTripStatus $status): array
    {
        return match ($status) {
            RegularTripStatus::Delayed => ['value' => 'waiting', 'label' => 'Waiting'],
            RegularTripStatus::Scheduled => ['value' => 'scheduled', 'label' => 'Scheduled'],
            RegularTripStatus::OnTrip => ['value' => 'on_trip', 'label' => 'On Trip'],
            RegularTripStatus::Completed => ['value' => 'completed', 'label' => 'Completed'],
            RegularTripStatus::Cancelled => ['value' => 'cancelled', 'label' => 'Cancelled'],
            RegularTripStatus::OffDuty => ['value' => 'off_duty', 'label' => 'Off Duty'],
        };
    }

    private function tripTypeDetail(RegularTrip $trip): string
    {
        $leg = $trip->isOutbound() ? 'Going' : 'Return';
        $direction = $trip->routeLabel();

        if (! str_contains($direction, '→')) {
            return $leg;
        }

        [, $to] = array_map('trim', explode('→', $direction, 2));

        return $to !== '' ? $leg.' (to '.$to.')' : $leg;
    }

    private function canViewPassengerDetails(): bool
    {
        $user = auth()->user();

        return $user?->role === UserRole::Administrator && $user->is_active;
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizedManifest(RegularTrip $trip): array
    {
        abort_unless($this->canViewPassengerDetails(), 403);

        $trip->loadMissing(['boat.operator', 'passengers']);

        abort_unless($trip->boat !== null, 404);

        return $this->transformRegularTrip($trip, true);
    }

    private function manifestDocument(RegularTrip $trip): HttpResponse
    {
        auth()->user()?->loadMissing('organization');

        $payload = $this->authorizedManifest($trip);
        $filename = $this->manifestFilename($payload);

        return response()
            ->view('reports.passenger-manifest', [
                'trip' => $payload,
                'filename' => $filename,
                'organization' => auth()->user()?->organization?->name ?? 'SafeSakay',
                'autoPrint' => request()->boolean('print'),
            ])
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    /**
     * @param  array<string, mixed>  $trip
     */
    private function manifestFilename(array $trip): string
    {
        $boat = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $trip['boat']['name']) ?: 'Boat';

        return 'Passenger-Manifest-'.$boat.'-'.$trip['trip_date'];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function rentalPaymentRows(int $organizationId): array
    {
        if ($organizationId === 0) {
            return [];
        }

        $rows = app(\App\Support\AdminFinanceLedger::class)->paymentRows($organizationId);
        $refunds = \App\Models\RentalRefundRequest::query()
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->get()
            ->groupBy('boat_booking_id')
            ->map->first();

        return collect($rows)->map(function (array $row) use ($refunds): array {
            $refund = $refunds->get($row['booking_id'] ?? null);

            return [
                ...$row,
                'refund_reference' => $refund?->displayReference(),
                'refund_status' => $refund?->status,
                'refund_status_label' => $refund?->statusLabel(),
            ];
        })->all();
    }

    private function bookingRecords(bool $canViewPassengerDetails): Collection
    {
        $bookings = BoatBooking::query()
            ->with(['boat.operator', 'paymentVerifiedBy', 'passengers'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->filter(fn (BoatBooking $booking) => $booking->boat !== null)
            ->values();

        $codes = [];
        $bookings->groupBy(fn (BoatBooking $booking) => $booking->booking_date->toDateString())
            ->each(function ($dayBookings, string $date) use (&$codes): void {
                $sequence = 1;
                foreach ($dayBookings as $booking) {
                    $codes[$booking->id] = sprintf('PR-%s-%03d', Carbon::parse($date)->format('Ymd'), $sequence);
                    $sequence++;
                }
            });

        return $bookings
            ->map(fn (BoatBooking $booking) => $this->transformBooking(
                $booking,
                $canViewPassengerDetails,
                $codes[$booking->id] ?? null,
            ))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBooking(BoatBooking $booking, bool $canViewPassengerDetails = false, ?string $displayCode = null): array
    {
        $time = Carbon::parse($booking->start_time);
        $payment = $booking->paymentStatus();
        $verifiedAmount = $payment === BookingPaymentStatus::Verified
            ? (float) ($booking->down_payment_amount ?? 0)
            : 0.0;
        $balancePaid = $booking->balance_status === BookingBalanceStatus::Paid
            ? (float) ($booking->remaining_balance ?? 0)
            : 0.0;
        $confirmedRevenue = $verifiedAmount + $balancePaid;
        $quoted = (float) ($booking->rental_price ?? $confirmedRevenue);
        $method = filled($booking->gcash_reference)
            ? 'GCash'
            : (filled($booking->payment_receipt_path) ? 'Bank transfer' : '—');
        $reportStatus = $this->rentalReportStatus($booking->status);

        return [
            'id' => $booking->id,
            'code' => $displayCode ?: ($booking->code ?? sprintf('PR-%s-%03d', $booking->booking_date->format('Ymd'), $booking->id)),
            'customer_name' => $booking->customer_name,
            'contact_number' => $booking->contact_number,
            'destination' => $booking->destination ?: $booking->package,
            'purpose' => $booking->purpose,
            'booking_date' => $booking->booking_date->toDateString(),
            'booking_date_label' => $booking->booking_date->format('M j, Y'),
            'original_booking_date' => ($booking->original_booking_date ?? $booking->booking_date)->toDateString(),
            'original_booking_date_label' => ($booking->original_booking_date ?? $booking->booking_date)->format('M j, Y'),
            'reschedule_status' => $booking->reschedule_status,
            'booking_date_long' => $booking->booking_date->format('F j, Y'),
            'start_time' => $time->format('H:i'),
            'start_time_label' => $time->format('g:i A'),
            'passenger_count' => $booking->passenger_count,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'report_status' => $reportStatus['value'],
            'report_status_label' => $reportStatus['label'],
            'remarks' => $booking->remarks,
            'payment_status' => $payment->value,
            'payment_status_label' => $payment->label(),
            'payment_method' => $method,
            'gcash_reference' => $booking->gcash_reference,
            'amount' => $confirmedRevenue,
            'amount_label' => OrganizationRoute::formatPeso((string) number_format($confirmedRevenue, 2, '.', '')),
            'quoted_amount' => $quoted,
            'quoted_amount_label' => OrganizationRoute::formatPeso((string) number_format($quoted, 2, '.', '')),
            'verified_amount' => $verifiedAmount,
            'payment_verified_at_label' => $booking->payment_verified_at?->format('M d, Y · g:i A'),
            'payment_verified_by_name' => $booking->paymentVerifiedBy?->name,
            'recorded_passengers' => $canViewPassengerDetails
                ? $booking->passengers->map(fn ($passenger) => [
                    'id' => $passenger->id,
                    'full_name' => $passenger->full_name,
                    'age' => $passenger->age,
                    'gender' => $passenger->gender,
                    'contact_number' => $passenger->contact_number,
                ])->values()->all()
                : [],
            'boat' => [
                'id' => $booking->boat->id,
                'name' => $booking->boat->name,
                'code' => $booking->boat->code,
                'capacity' => $booking->boat->capacity,
                'operator_name' => $booking->boat->operator?->full_name,
            ],
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function rentalReportStatus(BoatBookingStatus $status): array
    {
        return match ($status) {
            BoatBookingStatus::Pending, BoatBookingStatus::AwaitingPayment => ['value' => 'pending', 'label' => 'Pending'],
            BoatBookingStatus::Confirmed => ['value' => 'approved', 'label' => 'Approved'],
            BoatBookingStatus::Ongoing => ['value' => 'on_trip', 'label' => 'On Trip'],
            BoatBookingStatus::WeatherHold => ['value' => 'weather_hold', 'label' => 'Weather Hold'],
            BoatBookingStatus::ReschedulePending => ['value' => 'reschedule_pending', 'label' => 'Reschedule Pending'],
            BoatBookingStatus::Completed => ['value' => 'completed', 'label' => 'Completed'],
            BoatBookingStatus::PassengerNoShow => ['value' => 'passenger_no_show', 'label' => 'Passenger No-Show'],
            BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected => ['value' => 'cancelled', 'label' => 'Cancelled'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tripRevenue(RegularTrip $trip): array
    {
        $route = $this->fareRouteFor($trip);
        $capacity = max(0, (int) ($trip->boat?->capacity ?? 0));
        $passengers = $trip->manifestPassengers()->take($capacity);
        $departed = $trip->departed_at !== null || $trip->status === RegularTripStatus::Completed;
        $total = 0.0;

        foreach ($passengers as $passenger) {
            $total += $route?->fareAmountFor($passenger->fareType()) ?? 0;
        }

        $collected = $departed && $trip->status !== RegularTripStatus::Cancelled
            ? round($total, 2)
            : 0.0;

        return [
            'revenue' => round($total, 2),
            'revenue_label' => OrganizationRoute::formatPeso($total),
            'departed' => $departed,
            'collected_fare' => $collected,
            'collected_fare_label' => OrganizationRoute::formatPeso($collected),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operatorRecords(int $organizationId): array
    {
        return Operator::query()
            ->with(['boat', 'violations'])
            ->whereHas('boat', fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('full_name')
            ->get()
            ->map(function (Operator $operator): array {
                $suspended = $operator->account_suspension_ends_at?->isFuture() ?? false;

                return [
                    'id' => $operator->id,
                    'name' => $operator->full_name,
                    'boat_id' => $operator->boat_id,
                    'boat_name' => $operator->boat?->name,
                    'boat_code' => $operator->boat?->code,
                    'capacity' => (int) ($operator->boat?->capacity ?? 0),
                    'violation_count' => $operator->violations->count(),
                    'suspended' => $suspended,
                    'suspension_reason' => $suspended ? ($operator->account_suspension_reason ?: 'Multiple violations') : null,
                    'suspension_start' => $suspended ? $operator->account_suspended_at?->timezone('Asia/Manila')->format('M j, Y') : null,
                    'suspension_end' => $suspended ? $operator->account_suspension_ends_at?->timezone('Asia/Manila')->format('M j, Y') : null,
                    'violations' => $operator->violations->sortBy('id')->map(fn ($violation) => [
                        'id' => $violation->id,
                        'date' => $violation->incident_date?->toDateString(),
                        'date_label' => $violation->incident_date?->format('M j, Y'),
                        'booking_code' => $violation->booking_code,
                        'reason' => $violation->reason,
                        'strike' => $violation->strike_number,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function fareRouteFor(RegularTrip $trip): ?OrganizationRoute
    {
        $routes = $this->regularFareRoutes();

        $matched = $routes->first(
            fn (OrganizationRoute $route) => $route->matchesTrip(
                $trip->route,
                $trip->direction ?: $trip->route,
            ),
        );

        if ($matched !== null) {
            return $matched;
        }

        return $routes->first(fn (OrganizationRoute $route) => $route->regularFareAmount() !== null);
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
}
