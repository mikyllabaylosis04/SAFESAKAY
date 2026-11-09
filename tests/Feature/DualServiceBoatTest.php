<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\RegularTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DualServiceBoatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_on_duty_boats_that_offer_both_services_stay_open_for_private_booking(): void
    {
        $admin = User::factory()->create();
        $boat = $this->bothServiceBoat($admin);
        $this->scheduleToday($admin, $boat);

        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);

        $this->actingAs($admin)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->has('availableBoats', 1)
                ->where('availableBoats.0.id', $boat->id)
                ->where('availableBoats.0.is_open_for_booking', true)
                ->where('availableBoats.0.availability_label', 'On duty — open for booking'));
    }

    public function test_confirmed_private_booking_cancels_that_days_regular_trips(): void
    {
        $admin = User::factory()->create();
        $boat = $this->bothServiceBoat($admin);
        $trip = $this->scheduleToday($admin, $boat);

        $this->actingAs($admin)
            ->post(route('boat-bookings.store'), $this->bookingPayload($boat, BoatBookingStatus::Confirmed))
            ->assertRedirect(route('schedules.boat-booking-schedule'));

        $this->assertSame(RegularTripStatus::Cancelled, $trip->fresh()->status);
        $this->assertSame('Skipped — private booking scheduled.', $trip->fresh()->remarks);
        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
    }

    public function test_pending_booking_does_not_cancel_regular_trips(): void
    {
        $admin = User::factory()->create();
        $boat = $this->bothServiceBoat($admin);
        $trip = $this->scheduleToday($admin, $boat);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00099',
            'customer_name' => 'Waiting Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-19',
            'start_time' => '11:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->assertSame(RegularTripStatus::Scheduled, $trip->fresh()->status);
        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
    }

    public function test_operator_approve_cancels_the_regular_trip_for_that_day(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);
        $boat = $this->bothServiceBoat($admin);
        $boat->operator?->update(['user_id' => $operator->id]);
        $trip = $this->scheduleToday($admin, $boat);

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00100',
            'customer_name' => 'Maria',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-19',
            'start_time' => '11:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->assertSame(RegularTripStatus::Cancelled, $trip->fresh()->status);
        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
    }

    public function test_cannot_assign_a_regular_trip_when_the_boat_has_a_confirmed_booking(): void
    {
        $admin = User::factory()->create();
        $route = $admin->organization?->seedDefaultRoute();
        $boat = $this->bothServiceBoat($admin);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00101',
            'customer_name' => 'Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-19',
            'start_time' => '11:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->post(route('regular-trips.store'), [
                'boat_id' => $boat->id,
                'route' => $route?->name,
                'direction' => $route?->origin.' → '.$route?->destination,
                'trip_date' => '2026-09-19',
                'departure_time' => '08:00',
                'rotation_order' => 1,
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'))
            ->assertSessionHasErrors('boat_id');
    }

    public function test_on_trip_boat_cannot_take_a_private_booking_today(): void
    {
        $admin = User::factory()->create();
        $boat = $this->bothServiceBoat($admin);
        $trip = $this->scheduleToday($admin, $boat);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->assertSame(BoatStatus::OnTrip, $boat->fresh()->status);

        $this->actingAs($admin)
            ->from(route('schedules.boat-booking-schedule'))
            ->post(route('boat-bookings.store'), $this->bookingPayload($boat, BoatBookingStatus::Confirmed))
            ->assertRedirect(route('schedules.boat-booking-schedule'))
            ->assertSessionHasErrors();
    }

    public function test_on_trip_boat_stays_listed_for_private_rental_and_can_be_booked_on_another_day(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $boat = $this->bothServiceBoat($admin);
        $trip = $this->scheduleToday($admin, $boat);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->assertSame(BoatStatus::OnTrip, $boat->fresh()->status);
        $this->assertTrue($boat->fresh()->isListedForPrivateBooking());
        $this->assertFalse($boat->fresh()->isEligibleForPrivateBooking());
        $this->assertTrue($boat->fresh()->isEligibleForPrivateBooking('2026-09-20'));

        $this->actingAs($admin)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->has('availableBoats', 1)
                ->where('availableBoats.0.id', $boat->id)
                ->where('availableBoats.0.is_open_for_booking', false)
                ->where('availableBoats.0.availability_label', 'On trip — not available today'));

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonPath('data.0.id', $boat->id)
            ->assertJsonPath('data.0.open_for_booking', true)
            ->assertJsonPath('data.0.open_for_booking_today', false)
            ->assertJsonPath('data.0.unavailable_today', true);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/boats/'.$boat->id.'/availability?date=2026-09-19')
            ->assertOk()
            ->assertJsonPath('day_available', false);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Cloud 9',
                'pickup_point' => 'Lakandula Port',
                'booking_date' => '2026-09-19',
                'start_time' => '11:00',
                'passenger_count' => 4,
                'contact_number' => '09171234567',
            ])
            ->assertStatus(422);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Cloud 9',
                'pickup_point' => 'Lakandula Port',
                'booking_date' => '2026-09-20',
                'start_time' => '08:00',
                'passenger_count' => 4,
                'contact_number' => '09171234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.booking_date', '2026-09-20');
    }

    public function test_scheduled_regular_trip_does_not_close_rental_date_or_time(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $boat = $this->bothServiceBoat($admin);
        $this->scheduleToday($admin, $boat);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/boats/'.$boat->id.'/availability?date=2026-09-19')
            ->assertOk()
            ->assertJsonPath('day_available', true)
            ->assertJsonPath('day_booked', false)
            ->assertJsonPath('times.0.value', '00:00')
            ->assertJsonPath('times.95.value', '23:45')
            ->assertJsonPath('times.32.value', '08:00')
            ->assertJsonPath('times.32.available', true);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Cloud 9',
                'pickup_point' => 'Lakandula Port',
                'booking_date' => '2026-09-19',
                'start_time' => '08:00',
                'passenger_count' => 4,
                'contact_number' => '09171234567',
            ])
            ->assertCreated();
    }

    public function test_passenger_can_book_any_typed_time_of_day(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $boat = $this->bothServiceBoat($admin);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Cloud 9',
                'pickup_point' => 'Lakandula Port',
                'booking_date' => now()->addDays(2)->toDateString(),
                'start_time' => '23:37',
                'passenger_count' => 2,
                'contact_number' => '09171234567',
            ])
            ->assertCreated();
    }

    private function bothServiceBoat(User $admin): Boat
    {
        return Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
    }

    private function scheduleToday(User $admin, Boat $boat): RegularTrip
    {
        return RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-09-19',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(Boat $boat, BoatBookingStatus $status): array
    {
        return [
            'boat_id' => $boat->id,
            'customer_name' => 'Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-19',
            'start_time' => '11:00',
            'passenger_count' => 4,
            'status' => $status->value,
        ];
    }
}
