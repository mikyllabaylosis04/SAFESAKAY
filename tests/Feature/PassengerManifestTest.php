<?php

namespace Tests\Feature;

use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Organization;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PassengerManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_recorded_passengers_for_their_trip(): void
    {
        [$admin, $trip] = $this->manifestTrip();

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->where('canViewPassengerDetails', true)
                ->has('regularTrips', 1)
                ->where('regularTrips.0.direction', 'Lakandula → Placer')
                ->where('regularTrips.0.passenger_count', 2)
                ->where('regularTrips.0.boat.capacity', 30)
                ->where('regularTrips.0.passengers.0.full_name', 'Juan Dela Cruz')
                ->where('regularTrips.0.passengers.1.full_name', 'Maria Santos')
                ->has('regularTrips.0.revenue')
                ->has('regularTrips.0.passengers.0.fare_amount_label')
                ->missing('regularTrips.0.passengers.0.address'));
    }

    public function test_non_administrators_cannot_see_passenger_details(): void
    {
        [$admin, $trip] = $this->manifestTrip();

        foreach ([UserRole::User] as $role) {
            $account = User::factory()->create([
                'organization_id' => $admin->organization_id,
                'role' => $role,
            ]);

            $this->actingAs($account)
                ->get(route('reports.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canViewPassengerDetails', false)
                    ->where('regularTrips.0.passenger_count', 2)
                    ->where('regularTrips.0.passengers', []));

            $this->actingAs($account)
                ->get(route('reports.manifest.print', $trip))
                ->assertForbidden();
        }
    }

    public function test_passenger_count_never_exceeds_boat_capacity(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Daryl Mae',
            'capacity' => 2,
            'service_type' => 'regular_trip',
        ]);

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-09-06',
            'departure_time' => '08:00',
            'passenger_count' => 4,
            'status' => RegularTripStatus::Completed,
        ]);

        foreach (['One', 'Two', 'Three', 'Four'] as $index => $name) {
            TripPassenger::query()->create([
                'organization_id' => $admin->organization_id,
                'regular_trip_id' => $trip->id,
                'full_name' => $name,
                'age' => 20 + $index,
                'gender' => 'Female',
                'contact_number' => '0917123456'.$index,
                'sort_order' => $index + 1,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('regularTrips.0.passenger_count', 2)
                ->has('regularTrips.0.passengers', 2));
    }

    public function test_other_organization_trips_are_hidden(): void
    {
        [$admin] = $this->manifestTrip();
        $other = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $other->organization_id,
            'service_type' => 'regular_trip',
        ]);

        RegularTrip::query()->create([
            'organization_id' => $other->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Dapa ↔ General Luna',
            'direction' => 'Dapa → General Luna',
            'rotation_order' => 1,
            'trip_date' => '2026-09-06',
            'departure_time' => '09:00',
            'passenger_count' => 1,
            'status' => RegularTripStatus::Completed,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('regularTrips', 1)
                ->where('regularTrips.0.direction', 'Lakandula → Placer'));
    }

    /**
     * @return array{0: User, 1: RegularTrip}
     */
    private function manifestTrip(): array
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Lakandula',
            'address' => 'Lakandula, Siargao',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Daryl Mae',
            'capacity' => 30,
            'service_type' => 'regular_trip',
        ]);

        $trip = RegularTrip::query()->create([
            'organization_id' => $organization->id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-09-06',
            'departure_time' => '08:00',
            'passenger_count' => 2,
            'status' => RegularTripStatus::Completed,
        ]);

        TripPassenger::query()->create([
            'organization_id' => $organization->id,
            'regular_trip_id' => $trip->id,
            'full_name' => 'Juan Dela Cruz',
            'age' => 25,
            'gender' => 'Male',
            'contact_number' => '09171234567',
            'sort_order' => 1,
        ]);
        TripPassenger::query()->create([
            'organization_id' => $organization->id,
            'regular_trip_id' => $trip->id,
            'full_name' => 'Maria Santos',
            'age' => 21,
            'gender' => 'Female',
            'contact_number' => '09181234567',
            'sort_order' => 2,
        ]);

        return [$admin, $trip];
    }
}
