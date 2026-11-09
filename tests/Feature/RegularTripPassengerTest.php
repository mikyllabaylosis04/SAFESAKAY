<?php

namespace Tests\Feature;

use App\Enums\PassengerFareType;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinishesRegularTripCycles;
use Tests\TestCase;

class RegularTripPassengerTest extends TestCase
{
    use FinishesRegularTripCycles;
    use RefreshDatabase;

    public function test_view_opens_trip_details_with_passenger_manifest(): void
    {
        [$admin, $trip] = $this->scheduledTrip(capacity: 20, passengers: [
            ['Maria Santos', 21, 'Female', '09181234567', 'PWD'],
        ]);

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/RegularTripDetails')
                ->where('trip.id', $trip->id)
                ->where('trip.passenger_count', 1)
                ->where('trip.boat.capacity', 20)
                ->where('trip.at_capacity', false)
                ->where('trip.can_encode_passengers', true)
                ->where('trip.passengers.0.full_name', 'Maria Santos')
                ->where('trip.passengers.0.remarks', 'PWD')
                ->where('statusOptions.0.value', 'scheduled'));
    }

    public function test_admin_can_add_a_passenger_and_see_them_in_the_manifest_report(): void
    {
        [$admin, $trip] = $this->scheduledTrip(capacity: 20);

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Juan Dela Cruz',
                'age' => 25,
                'gender' => 'Male',
                'contact_number' => '09171234567',
                'remarks' => 'Infant companion',
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertDatabaseHas('trip_passengers', [
            'regular_trip_id' => $trip->id,
            'full_name' => 'Juan Dela Cruz',
            'age' => 25,
            'gender' => 'Male',
            'contact_number' => '09171234567',
            'remarks' => 'Infant companion',
        ]);

        $this->assertSame(1, $trip->fresh()->passenger_count);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->where('regularTrips.0.id', $trip->id)
                ->where('regularTrips.0.passenger_count', 1)
                ->where('regularTrips.0.passengers.0.full_name', 'Juan Dela Cruz')
                ->where('regularTrips.0.passengers.0.remarks', 'Infant companion'));
    }

    public function test_passengers_cannot_exceed_boat_capacity(): void
    {
        [$admin, $trip] = $this->scheduledTrip(capacity: 1, passengers: [
            ['First Passenger', 30, 'Female', '09170000001'],
        ]);

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $trip))
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Second Passenger',
                'age' => 28,
                'gender' => 'Male',
                'contact_number' => '09170000002',
            ])
            ->assertRedirect(route('regular-trips.show', $trip))
            ->assertSessionHasErrors('full_name');

        $this->assertSame(1, TripPassenger::query()->where('regular_trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->fresh()->passenger_count);
    }

    public function test_admin_can_update_and_delete_a_passenger(): void
    {
        [$admin, $trip] = $this->scheduledTrip(capacity: 10, passengers: [
            ['Old Name', 40, 'Male', '09171111111', 'Seat near exit'],
        ]);

        $passenger = $trip->passengers()->first();

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.update', [$trip, $passenger]), [
                'full_name' => 'Updated Name',
                'age' => 41,
                'gender' => 'Male',
                'contact_number' => '09172222222',
                'remarks' => 'Window seat',
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertDatabaseHas('trip_passengers', [
            'id' => $passenger->id,
            'full_name' => 'Updated Name',
            'remarks' => 'Window seat',
        ]);
        $this->assertSame(1, TripPassenger::query()->count());

        $this->actingAs($admin)
            ->delete(route('regular-trips.passengers.destroy', [$trip, $passenger]))
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertDatabaseMissing('trip_passengers', ['id' => $passenger->id]);
        $this->assertSame(0, $trip->fresh()->passenger_count);
    }

    public function test_admin_can_update_trip_status_from_details(): void
    {
        [$admin, $trip] = $this->scheduledTrip();

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertSame(RegularTripStatus::OnTrip, $trip->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::Cancelled->value,
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertSame(RegularTripStatus::Cancelled, $trip->fresh()->status);

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $trip))
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Late Passenger',
                'age' => 22,
                'gender' => 'Female',
                'contact_number' => '09173333333',
            ])
            ->assertRedirect(route('regular-trips.show', $trip))
            ->assertSessionHasErrors('full_name');
    }

    public function test_passengers_cannot_be_added_after_departure(): void
    {
        [$admin, $trip] = $this->scheduledTrip();

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $trip))
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Late Passenger',
                'age' => 22,
                'gender' => 'Female',
                'contact_number' => '09173333333',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('full_name');
    }

    public function test_admin_can_add_a_passenger_without_remarks(): void
    {
        [$admin, $trip] = $this->scheduledTrip();

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Caicai',
                'age' => 21,
                'fare_type' => 'student',
                'gender' => 'Female',
                'contact_number' => '09876789876',
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertDatabaseHas('trip_passengers', [
            'regular_trip_id' => $trip->id,
            'full_name' => 'Caicai',
            'fare_type' => 'student',
            'remarks' => null,
        ]);
    }

    public function test_trip_revenue_totals_passenger_fares_from_the_route(): void
    {
        [$admin, $trip] = $this->scheduledTrip(passengers: [
            ['Ana Regular', 30, 'Female', '09170000001'],
            ['Ben Student', 18, 'Male', '09170000002'],
            ['Cora Senior', 65, 'Female', '09170000003'],
        ]);

        OrganizationRoute::query()->updateOrCreate(
            [
                'organization_id' => $admin->organization_id,
                'name' => 'Lakandula ↔ Placer',
            ],
            [
                'origin' => 'Lakandula',
                'destination' => 'Placer',
                'trip_type' => 'regular_public',
                'is_active' => true,
                'regular_fare' => 50,
                'student_discount_percent' => 20,
                'senior_discount_percent' => 15,
            ],
        );

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.revenue', 132.5)
                ->where('trip.revenue_label', '₱132.50')
                ->where('trip.fares.regular_fare', 50)
                ->where('trip.fares.student_fare', 40)
                ->where('trip.fares.senior_fare', 42.5)
                ->where('trip.fares.student_discount_percent', 20)
                ->where('trip.fares.senior_discount_percent', 15)
                ->where('trip.fare_counts.regular', 1)
                ->where('trip.fare_counts.student', 1)
                ->where('trip.fare_counts.senior', 1)
                ->where('trip.outbound_revenue', 0)
                ->where('trip.return_revenue', 0)
                ->where('trip.total_revenue', 0)
                ->where('trip.outbound_revenue_label', '₱0.00')
                ->where('trip.total_revenue_label', '₱0.00'));
    }

    public function test_collected_round_trip_revenue_counts_only_departed_passenger_fares(): void
    {
        [$admin, $trip] = $this->scheduledTrip(passengers: [
            ['Ana Regular', 30, 'Female', '09170000001'],
            ['Ben Student', 18, 'Male', '09170000002'],
            ['Cora Senior', 65, 'Female', '09170000003'],
        ]);

        OrganizationRoute::query()->updateOrCreate(
            [
                'organization_id' => $admin->organization_id,
                'name' => 'Lakandula ↔ Placer',
            ],
            [
                'origin' => 'Lakandula',
                'destination' => 'Placer',
                'trip_type' => 'regular_public',
                'is_active' => true,
                'regular_fare' => 50,
                'student_discount_percent' => 20,
                'senior_discount_percent' => 15,
            ],
        );

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.status', 'on_trip')
                ->where('trip.revenue', 132.5)
                ->where('trip.outbound_revenue', 132.5)
                ->where('trip.outbound_revenue_label', '₱132.50')
                ->where('trip.return_revenue', 0)
                ->where('trip.return_revenue_label', '₱0.00')
                ->where('trip.total_revenue', 132.5)
                ->where('trip.total_revenue_label', '₱132.50')
                ->where('trip.paired_return', null));

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $trip))
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ])
            ->assertRedirect();

        $return = RegularTrip::query()->where('return_of_id', $trip->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Return Rider',
                'age' => 30,
                'gender' => 'Female',
                'contact_number' => '09170000009',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.outbound_revenue', 132.5)
                ->where('trip.return_revenue', 0)
                ->where('trip.total_revenue', 132.5)
                ->where('trip.paired_return.started', true)
                ->where('trip.paired_return.departed', false));

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $return))
            ->patch(route('regular-trips.status.update', $return), [
                'status' => $return->fresh()->status->value,
                'action' => 'depart',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.outbound_revenue', 132.5)
                ->where('trip.return_revenue', 50)
                ->where('trip.return_revenue_label', '₱50.00')
                ->where('trip.total_revenue', 182.5)
                ->where('trip.total_revenue_label', '₱182.50')
                ->where('trip.paired_return.departed', true));
    }

    public function test_todays_revenue_keeps_completed_trips_until_the_next_day(): void
    {
        [$admin, $trip] = $this->scheduledTrip(passengers: [
            ['Ana Regular', 30, 'Female', '09170000001'],
        ]);

        OrganizationRoute::query()->updateOrCreate(
            [
                'organization_id' => $admin->organization_id,
                'name' => 'Lakandula ↔ Placer',
            ],
            [
                'origin' => 'Lakandula',
                'destination' => 'Placer',
                'trip_type' => 'regular_public',
                'is_active' => true,
                'regular_fare' => 50,
                'student_discount_percent' => 20,
                'senior_discount_percent' => 20,
            ],
        );

        $this->finishRegularTripCycle($admin, $trip, returnToQueue: true);

        $this->assertSame(RegularTripStatus::Completed, $trip->fresh()->status);

        $next = RegularTrip::query()
            ->where('boat_id', $trip->boat_id)
            ->where('status', RegularTripStatus::Scheduled)
            ->latest('id')
            ->get()
            ->first(fn (RegularTrip $item) => $item->isOutbound())
            ?? RegularTrip::query()->where('boat_id', $trip->boat_id)->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips', function ($trips) use ($trip) {
                    $completed = collect($trips)->firstWhere('id', $trip->id);

                    return $completed
                        && $completed['status'] === 'completed'
                        && (float) $completed['revenue'] === 50.0
                        && (float) $completed['boat_revenue_today'] === 50.0;
                }));

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $next))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.id', $next->id)
                ->where('trip.revenue', 0)
                ->where('trip.boat_revenue_today', 50)
                ->where('trip.boat_revenue_today_label', '₱50.00'));

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $next), [
                'status' => RegularTripStatus::OffDuty->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.status', 'completed')
                ->where('trip.revenue', 50)
                ->where('trip.boat_revenue_today', 50));
    }

    public function test_other_organization_cannot_view_or_encode_passengers(): void
    {
        [, $trip] = $this->scheduledTrip();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get(route('regular-trips.show', $trip))
            ->assertNotFound();

        $this->actingAs($other)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Intruder',
                'age' => 20,
                'gender' => 'Male',
                'contact_number' => '09174444444',
            ])
            ->assertNotFound();
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string, 3: string, 4?: string}>  $passengers
     * @return array{0: User, 1: RegularTrip}
     */
    private function scheduledTrip(int $capacity = 20, array $passengers = []): array
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Daryl Mae',
            'capacity' => $capacity,
            'service_type' => 'regular_trip',
        ]);

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-09-18',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        foreach ($passengers as $index => $passenger) {
            TripPassenger::query()->create([
                'organization_id' => $admin->organization_id,
                'regular_trip_id' => $trip->id,
                'full_name' => $passenger[0],
                'age' => $passenger[1],
                'gender' => $passenger[2],
                'contact_number' => $passenger[3],
                'remarks' => $passenger[4] ?? null,
                'fare_type' => $passenger[5] ?? PassengerFareType::fromAge((int) $passenger[1])->value,
                'sort_order' => $index + 1,
            ]);
        }

        $trip->syncPassengerCount();

        return [$admin, $trip->fresh(['boat.operator', 'passengers'])];
    }
}
