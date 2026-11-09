<?php

namespace Tests\Feature;

use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegularTripReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_trip_report_lists_one_record_per_round_trip(): void
    {
        [$admin, $going] = $this->pairedTrips();

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->has('regularTrips', 1)
                ->where('regularTrips.0.id', $going->id)
                ->where('regularTrips.0.code', 'RT-20261001-001')
                ->where('regularTrips.0.leg_label', 'Regular')
                ->where('regularTrips.0.report_status_label', 'Completed')
                ->where('regularTrips.0.passenger_count', 3)
                ->where('regularTrips.0.passengers.0.full_name', 'Juan Dela Cruz')
                ->where('regularTrips.0.passengers.2.full_name', 'Carlo Reyes'));
    }

    public function test_waiting_status_is_exposed_for_delayed_trips(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'capacity' => 20,
        ]);
        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-10-01',
            'departure_time' => '09:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Delayed,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('regularTrips.0.report_status', 'waiting')
                ->where('regularTrips.0.report_status_label', 'Waiting'));
    }

    public function test_regular_trip_filters_are_applied_on_the_backend(): void
    {
        [$admin, $going, $returning] = $this->pairedTrips();
        $otherBoat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'ANGEL BOMB',
            'capacity' => 20,
            'service_type' => 'regular_trip',
        ]);
        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $otherBoat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 2,
            'trip_date' => '2026-10-15',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index', [
                'from' => '2026-10-01',
                'to' => '2026-10-31',
                'boat' => $going->boat_id,
                'type' => 'going',
                'status' => 'completed',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('regularTrips', 2)
                ->has('filteredRegularTrips', 1)
                ->where('filteredRegularTrips.0.id', $going->id)
                ->where('filteredRegularTrips.0.leg_label', 'Regular')
                ->where('tripFilters.boat', (string) $going->boat_id)
                ->where('tripFilters.type', 'going')
                ->where('tripFilters.status', 'completed')
                ->where('reportBoats.0.name', 'ANGEL BOMB'));
    }

    public function test_regular_trip_default_filter_uses_the_current_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

        [$admin, $going] = $this->pairedTrips();
        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $going->boat_id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 3,
            'trip_date' => '2026-09-06',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Completed,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('regularTrips', 2)
                ->has('filteredRegularTrips', 1)
                ->where('tripFilters.from', now()->copy()->startOfMonth()->toDateString())
                ->where('tripFilters.to', now()->copy()->endOfMonth()->toDateString()));
    }

    /**
     * @return array{0: User, 1: RegularTrip, 2: RegularTrip}
     */
    private function pairedTrips(): array
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Boat 02',
            'capacity' => 20,
            'service_type' => 'regular_trip',
        ]);

        $going = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-10-01',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Completed,
        ]);
        $returning = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Placer → Lakandula',
            'rotation_order' => 1,
            'trip_date' => '2026-10-01',
            'departure_time' => '11:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::OnTrip,
        ]);

        foreach (['Juan Dela Cruz', 'Maria Santos'] as $index => $name) {
            TripPassenger::query()->create([
                'organization_id' => $admin->organization_id,
                'regular_trip_id' => $going->id,
                'full_name' => $name,
                'age' => 25,
                'gender' => 'Female',
                'contact_number' => '0917000000'.($index + 1),
                'sort_order' => $index + 1,
            ]);
        }

        TripPassenger::query()->create([
            'organization_id' => $admin->organization_id,
            'regular_trip_id' => $returning->id,
            'full_name' => 'Carlo Reyes',
            'age' => 30,
            'gender' => 'Male',
            'contact_number' => '09170000003',
            'sort_order' => 1,
        ]);

        $going->syncPassengerCount();
        $returning->syncPassengerCount();

        return [$admin, $going->fresh(), $returning->fresh()];
    }
}
