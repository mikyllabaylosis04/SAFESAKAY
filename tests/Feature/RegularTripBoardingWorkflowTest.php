<?php

namespace Tests\Feature;

use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use App\Notifications\BoardingWaitReachedNotification;
use App\Services\RegularTripRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinishesRegularTripCycles;
use Tests\TestCase;

class RegularTripBoardingWorkflowTest extends TestCase
{
    use FinishesRegularTripCycles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 08:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_boarding_starts_when_a_passenger_is_typed_not_when_the_boat_is_first(): void
    {
        [$admin, $trip] = $this->lineupTrip();

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.scheduled_time_label', 'Leave When Ready')
                ->where('trip.has_departure_time', false)
                ->where('trip.boarding.wait_minutes', 30)
                ->where('trip.boarding.auto_departs', false)
                ->where('trip.boarding.phase', 'idle')
                ->where('trip.boarding.started_at_label', null));

        $this->assertNull($trip->fresh()->boarding_started_at);

        $this->actingAs($admin)
            ->postJson(route('regular-trips.boarding.start', $trip))
            ->assertOk()
            ->assertJsonPath('started_at_label', '4:00 PM');

        $started = $trip->fresh()->boarding_started_at?->toIso8601String();

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Maria',
                'age' => 28,
                'gender' => 'Female',
                'contact_number' => '09170000001',
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $trip->refresh();

        $this->assertSame($started, $trip->boarding_started_at?->toIso8601String());
        $this->assertSame('8:00 AM', $trip->boarding_started_at?->format('g:i A'));
        $this->assertSame('4:00 PM', $trip->boardingPayload()['started_at_label']);
        $this->assertSame('boarding', $trip->boardingPhase());
    }

    public function test_full_boat_is_ready_to_depart_but_does_not_leave_until_confirmed(): void
    {
        [$admin, $trip] = $this->lineupTrip(capacity: 1);

        $this->actingAs($admin)
            ->get(route('regular-trips.show', $trip))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Maria',
                'age' => 28,
                'gender' => 'Female',
                'contact_number' => '09170000001',
            ])
            ->assertRedirect(route('regular-trips.show', $trip));

        $trip->refresh();

        $this->assertTrue($trip->isAtCapacity());
        $this->assertSame('ready_to_depart', $trip->boardingPhase());
        $this->assertSame(RegularTripStatus::Scheduled, $trip->status);
        $this->assertNull($trip->departed_at);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->assertSame('4:00 PM', $trip->fresh()->actualDepartureLabel());
    }

    public function test_waiting_limit_notifies_operator_and_does_not_auto_depart(): void
    {
        [$admin, $trip] = $this->lineupTrip();
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $trip->boat->operator?->update(['user_id' => $operator->id]);

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $trip), [
                'full_name' => 'Maria',
                'age' => 28,
                'gender' => 'Female',
                'contact_number' => '09170000001',
            ])
            ->assertRedirect();

        $this->assertNotNull($trip->fresh()->boarding_started_at);

        Carbon::setTestNow('2026-10-14 08:30:00');

        $notified = app(RegularTripRotationService::class)->notifyBoardingWaitIfDue();

        $this->assertSame(1, $notified);
        $this->assertNotNull($trip->fresh()->boarding_wait_notified_at);
        $this->assertSame(RegularTripStatus::Scheduled, $trip->fresh()->status);
        $this->assertNull($trip->fresh()->departed_at);
        $this->assertSame('ready_for_review', $trip->fresh()->boardingPhase());

        Notification::assertSentTo($admin, BoardingWaitReachedNotification::class);
        Notification::assertSentTo($operator, BoardingWaitReachedNotification::class);
    }

    public function test_second_trip_gets_a_new_boarding_timer_and_actual_timestamps(): void
    {
        [$admin, $going] = $this->lineupTrip();
        app(RegularTripRotationService::class)->ensureReturnTrip($going);
        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $going), [
                'full_name' => 'Maria',
                'age' => 28,
                'gender' => 'Female',
                'contact_number' => '09170000001',
            ])
            ->assertRedirect();

        $firstStarted = $going->fresh()->boarding_started_at?->toIso8601String();

        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->finishRegularTripCycle($admin, $going);

        $second = RegularTrip::query()
            ->where('boat_id', $going->boat_id)
            ->where('cycle_number', 2)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertNotNull($second);
        $this->assertNull($second->departed_at);
        $this->assertNull($second->arrived_at);
        $this->assertNull($second->boarding_started_at);
        $this->assertSame('Leave When Ready', $second->scheduledTimeLabel());

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $second), [
                'full_name' => 'Pedro',
                'age' => 32,
                'gender' => 'Male',
                'contact_number' => '09170000002',
            ])
            ->assertRedirect();

        $this->assertNotNull($second->fresh()->boarding_started_at);
        $this->assertNotSame($firstStarted, $second->fresh()->boarding_started_at?->toIso8601String());
        $this->assertSame('6:00 PM', $second->fresh()->boardingPayload()['started_at_label']);

        Carbon::setTestNow('2026-10-14 11:15:00');

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $second), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->assertSame('7:15 PM', $second->fresh()->actualDepartureLabel());
        $this->assertSame('6:00 PM', $going->fresh()->actualDepartureLabel());
    }

    /**
     * @return array{0: User, 1: RegularTrip}
     */
    private function lineupTrip(int $capacity = 8): array
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'ANGEL BOMB',
            'code' => 'ANGEL',
            'capacity' => $capacity,
            'service_type' => 'regular_trip',
        ]);

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'cycle_number' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        return [$admin, $trip->fresh(['boat.operator'])];
    }
}
