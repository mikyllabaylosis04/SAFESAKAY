<?php

namespace Tests\Feature;

use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\FinishesRegularTripCycles;
use Tests\TestCase;

class BoatStatusWorkflowTest extends TestCase
{
    use FinishesRegularTripCycles;
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

    public function test_setting_a_regular_trip_today_puts_the_boat_on_duty(): void
    {
        [$admin, $boat] = $this->readyBoat();

        $this->actingAs($admin);
        $this->scheduleToday($admin, $boat);

        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
        $this->assertTrue($boat->fresh()->isEligibleForRegularTrip());
    }

    public function test_marking_the_trip_on_trip_puts_the_boat_on_trip(): void
    {
        [$admin, $boat] = $this->readyBoat();
        $trip = $this->scheduleToday($admin, $boat);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $this->assertSame(BoatStatus::OnTrip, $boat->fresh()->status);
    }

    public function test_completing_todays_trips_without_requeue_returns_the_boat_to_available(): void
    {
        [$admin, $boat] = $this->readyBoat();
        $trip = $this->scheduleToday($admin, $boat);

        $this->finishRegularTripCycle($admin, $trip, returnToQueue: false);

        $this->assertSame(BoatStatus::Available, $boat->fresh()->status);
    }

    public function test_completing_todays_trips_and_rejoining_the_queue_keeps_the_boat_on_duty(): void
    {
        [$admin, $boat] = $this->readyBoat();
        $trip = $this->scheduleToday($admin, $boat);

        $this->finishRegularTripCycle($admin, $trip, returnToQueue: true);

        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
        $this->assertTrue(
            RegularTrip::query()
                ->where('boat_id', $boat->id)
                ->whereDate('trip_date', '2026-09-19')
                ->where('status', RegularTripStatus::Scheduled)
                ->exists(),
        );
    }

    public function test_maintenance_is_not_overwritten_by_a_new_trip(): void
    {
        [$admin, $boat] = $this->readyBoat();
        $boat->update(['status' => BoatStatus::Maintenance]);

        RegularTrip::query()->create([
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

        $this->assertSame(BoatStatus::Maintenance, $boat->fresh()->status);
    }

    public function test_admin_cannot_manually_set_on_duty_from_the_fleet_list(): void
    {
        [$admin, $boat] = $this->readyBoat();

        $this->actingAs($admin)
            ->from(route('boats.index'))
            ->patch(route('boats.status.update', $boat), [
                'status' => BoatStatus::OnDuty->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(BoatStatus::Available, $boat->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Boat}
     */
    private function readyBoat(): array
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'regular_trip',
        ]);

        return [$admin, $boat];
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
}
