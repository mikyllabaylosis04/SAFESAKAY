<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BoatHistory;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\BoatStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinishesRegularTripCycles;
use Tests\TestCase;

class SystemAuditFixesTest extends TestCase
{
    use FinishesRegularTripCycles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_same_boat_cannot_join_the_lineup_twice_while_still_active(): void
    {
        $admin = User::factory()->create();
        $boat = $this->regularBoat($admin);
        $route = $admin->organization?->seedDefaultRoute();

        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name,
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->post(route('regular-trips.store'), [
                'boat_id' => $boat->id,
                'route' => $route?->name,
                'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
                'trip_date' => now()->toDateString(),
                'departure_time' => '10:00',
                'rotation_order' => 2,
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'))
            ->assertSessionHasErrors('boat_id');
    }

    public function test_maintenance_records_history_and_takes_waiting_boats_off_duty(): void
    {
        $admin = User::factory()->create();
        $boat = $this->regularBoat($admin);
        $route = $admin->organization?->seedDefaultRoute();

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name,
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->actingAs($admin)
            ->patch(route('boats.status.update', $boat), [
                'status' => BoatStatus::Maintenance->value,
            ])
            ->assertRedirect();

        $this->assertSame(BoatStatus::Maintenance, $boat->fresh()->status);
        $this->assertSame(RegularTripStatus::OffDuty, $trip->fresh()->status);
        $this->assertTrue(
            BoatHistory::query()
                ->where('boat_id', $boat->id)
                ->where('category', 'maintenance')
                ->exists(),
        );

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip->fresh()), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_completed_trip_can_rejoin_for_a_second_cycle(): void
    {
        $admin = User::factory()->create();
        $boat = $this->regularBoat($admin);
        $route = $admin->organization?->seedDefaultRoute();

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name,
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->finishRegularTripCycle($admin, $trip, returnToQueue: true);

        $this->assertTrue(
            RegularTrip::query()
                ->where('boat_id', $boat->id)
                ->whereDate('trip_date', now()->toDateString())
                ->where('status', RegularTripStatus::Completed)
                ->exists(),
        );
        $this->assertTrue(
            RegularTrip::query()
                ->where('boat_id', $boat->id)
                ->whereDate('trip_date', now()->toDateString())
                ->where('status', RegularTripStatus::Scheduled)
                ->exists(),
        );
        $this->assertTrue(
            BoatHistory::query()
                ->where('boat_id', $boat->id)
                ->where('category', 'trip')
                ->where('title', 'Completed regular trip')
                ->exists(),
        );
    }

    public function test_passenger_can_cancel_their_pending_rental(): void
    {
        $passenger = User::factory()->create(['role' => UserRole::Passenger]);
        $boat = Boat::factory()->create([
            'organization_id' => $passenger->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'private_booking',
            'service_availability' => [BoatService::PrivateBooking->value],
        ]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $passenger->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'customer_name' => $passenger->name,
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame($passenger->id, $booking->fresh()->cancelled_by);
    }

    public function test_rejected_payments_are_labeled_rejected_and_do_not_count_as_revenue(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'private_booking',
            'service_availability' => [BoatService::PrivateBooking->value],
        ]);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'customer_name' => 'Rejected Guest',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '4500.00',
            'down_payment_amount' => '900.00',
            'payment_status' => BookingPaymentStatus::Rejected,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->where('bookings.0.payment_status_label', 'Rejected')
                ->where('bookings.0.amount', 0));
    }

    public function test_committed_rental_blocks_a_regular_trip_on_the_same_date(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $route = $admin->organization?->seedDefaultRoute();

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'customer_name' => 'Private Guest',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->post(route('regular-trips.store'), [
                'boat_id' => $boat->id,
                'route' => $route?->name,
                'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
                'trip_date' => now()->toDateString(),
                'departure_time' => '08:00',
                'rotation_order' => 1,
            ])
            ->assertSessionHasErrors('boat_id');
    }

    public function test_sync_does_not_clear_maintenance_when_trips_exist(): void
    {
        $admin = User::factory()->create();
        $boat = $this->regularBoat($admin, BoatStatus::Maintenance);

        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        app(BoatStatusSyncService::class)->sync($boat->fresh());

        $this->assertSame(BoatStatus::Maintenance, $boat->fresh()->status);
    }

    private function regularBoat(User $admin, BoatStatus $status = BoatStatus::Available): Boat
    {
        return Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => $status,
            'service_type' => 'regular_trip',
            'service_availability' => [BoatService::RegularTrip->value],
        ]);
    }
}
