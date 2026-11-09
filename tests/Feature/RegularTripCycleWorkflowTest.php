<?php

namespace Tests\Feature;

use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\RegularTripRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinishesRegularTripCycles;
use Tests\TestCase;

class RegularTripCycleWorkflowTest extends TestCase
{
    use FinishesRegularTripCycles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 08:10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_trip_uses_leave_when_ready_and_records_actual_departure(): void
    {
        [$admin, $going] = $this->firstTrip();

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $going->refresh();

        $this->assertSame('Leave When Ready', $going->scheduledTimeLabel());
        $this->assertFalse($going->hasScheduledTime());
        $this->assertSame('4:10 PM', $going->actualDepartureLabel());
        $this->assertSame(1, $going->cycleNumber());
    }

    public function test_second_trip_uses_leave_when_ready_instead_of_eight_am(): void
    {
        [$admin, $going] = $this->firstTrip();
        $this->finishRegularTripCycle($admin, $going);

        $second = RegularTrip::query()
            ->where('boat_id', $going->boat_id)
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertNotNull($second);
        $this->assertNotSame($going->id, $second->id);
        $this->assertSame(2, $second->cycleNumber());
        $this->assertSame(RegularTripScheduleMode::Rush, $second->scheduleMode());
        $this->assertSame('Leave When Ready', $second->scheduledTimeLabel());
        $this->assertFalse($second->hasScheduledTime());
        $this->assertSame(RegularTripStatus::Completed, $going->fresh()->status);
    }

    public function test_second_trip_actual_departure_is_the_real_start_time(): void
    {
        [$admin, $going] = $this->firstTrip();
        $this->finishRegularTripCycle($admin, $going);

        $second = RegularTrip::query()
            ->where('boat_id', $going->boat_id)
            ->where('cycle_number', 2)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        Carbon::setTestNow('2026-10-14 10:30:00');

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $second), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->assertSame('6:30 PM', $second->fresh()->actualDepartureLabel());
        $this->assertSame('Leave When Ready', $second->fresh()->scheduledTimeLabel());
    }

    public function test_return_arrival_does_not_require_depart_or_fabricate_departure(): void
    {
        [$admin, $going] = $this->firstTrip();
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ])
            ->assertRedirect(route('regular-trips.show', ['regularTrip' => $return, 'encode' => 1]));

        $return->refresh();
        $this->assertNotNull($return->arrived_at);
        $this->assertNull($return->departed_at);
        $this->assertNull($going->fresh()->arrived_at);
        $this->assertSame('Not Recorded', $going->fresh()->actualArrivalLabel());
        $this->assertSame('Not Recorded', $return->actualDepartureLabel());
        $this->assertSame(RegularTripStatus::Scheduled, $return->status);
        $this->assertSame('Arrived at Origin', $return->workflowStatusLabel());
        $this->assertSame(RegularTripStatus::Completed, $going->fresh()->status);
    }

    public function test_return_rotation_lists_who_comes_back_first(): void
    {
        $admin = User::factory()->create();
        $route = $admin->organization?->seedDefaultRoute();
        $first = $this->makeGoing($admin, $route, 'ONE', 1, '06:00');
        $second = $this->makeGoing($admin, $route, 'TWO', 2, '08:00');
        app(RegularTripRotationService::class)->ensureReturnTrip($first);
        app(RegularTripRotationService::class)->ensureReturnTrip($second);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->actingAs($admin)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips', function ($trips) use ($first) {
                    $firstReturn = collect($trips)->first(function ($trip) use ($first) {
                        return is_array($trip)
                            && ($trip['is_outbound'] ?? true) === false
                            && $trip['trip_date'] === now()->toDateString()
                            && (int) ($trip['boat']['id'] ?? 0) === (int) $first->boat_id;
                    });

                    return is_array($firstReturn)
                        && $firstReturn['leave_order_label'] === '1st to return';
                }));
    }

    public function test_return_passengers_are_recorded_after_arrival(): void
    {
        [$admin, $going] = $this->firstTrip();
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), ['status' => RegularTripStatus::OnTrip->value]);

        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($admin)
            ->from(route('regular-trips.show', $return))
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Too Early',
                'age' => 20,
                'gender' => 'Male',
                'contact_number' => '09170000001',
            ])
            ->assertRedirect(route('regular-trips.show', $return))
            ->assertSessionHasErrors('full_name');

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ]);

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Return Rider',
                'age' => 22,
                'gender' => 'Female',
                'contact_number' => '09170000002',
            ])
            ->assertRedirect(route('regular-trips.show', $return));

        $this->assertSame(1, $return->fresh()->passenger_count);
    }

    public function test_return_cannot_complete_before_arrival(): void
    {
        [$admin, $going] = $this->firstTrip();
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), ['status' => RegularTripStatus::OnTrip->value]);

        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
    }

    public function test_overlapping_active_cycles_are_blocked(): void
    {
        [$admin, $going] = $this->firstTrip();
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), ['status' => RegularTripStatus::OnTrip->value]);

        $clone = $going->replicate();
        $clone->departure_time = '10:00';
        $clone->rotation_order = 2;
        $clone->save();

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $clone), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_second_cycle_rejoins_last_in_the_queue(): void
    {
        $admin = User::factory()->create();
        $route = $admin->organization?->seedDefaultRoute();
        $first = $this->makeGoing($admin, $route, 'ONE', 1, '06:00');
        $second = $this->makeGoing($admin, $route, 'TWO', 2, '08:00');
        app(RegularTripRotationService::class)->ensureReturnTrip($first);
        app(RegularTripRotationService::class)->ensureReturnTrip($second);

        $this->finishRegularTripCycle($admin, $first);

        $waiting = RegularTrip::query()
            ->whereDate('trip_date', now()->toDateString())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->sortBy(fn (RegularTrip $trip) => (int) ($trip->queue_order ?: $trip->rotation_order))
            ->values();

        $this->assertSame(
            [$second->boat_id, $first->boat_id],
            $waiting->pluck('boat_id')->all(),
        );
        $this->assertSame([2, 1], $waiting->pluck('rotation_order')->all());
        $this->assertSame(2, $waiting->last()->cycleNumber());
    }

    public function test_a_new_arrival_does_not_reuse_an_older_return_passenger_list(): void
    {
        [$admin, $going] = $this->firstTrip();
        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->actingAs($admin)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ]);

        $this->actingAs($admin)
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Earlier Rider',
                'age' => 30,
                'gender' => 'Female',
                'contact_number' => '09170000008',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $next = $going->replicate();
        $next->departure_time = '11:00';
        $next->cycle_number = 2;
        $next->status = RegularTripStatus::Scheduled;
        $next->departed_at = null;
        $next->arrived_at = null;
        $next->completed_at = null;
        $next->passenger_count = 0;
        $next->save();

        $rotation = app(RegularTripRotationService::class);
        $freshReturn = $rotation->ensureReturnTrip($next);

        $this->assertNotSame($return->id, $freshReturn->id);
        $this->assertSame(0, $freshReturn->passengers()->count());
        $this->assertSame($going->id, $return->fresh()->return_of_id);
        $this->assertSame(1, $return->fresh()->passenger_count);
    }

    public function test_completing_a_cycle_does_not_duplicate_the_return_record(): void
    {
        [$admin, $going] = $this->firstTrip();
        $this->finishRegularTripCycle($admin, $going);

        $returns = RegularTrip::query()
            ->where('boat_id', $going->boat_id)
            ->get()
            ->reject(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertCount(2, $returns);
        $this->assertCount(2, $returns->pluck('id')->unique());
        $this->assertTrue($returns->every(fn (RegularTrip $trip) => $trip->return_of_id !== null));
    }

    public function test_operator_can_run_the_return_workflow_from_the_api(): void
    {
        [$admin, $going] = $this->firstTrip();
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $going->boat->operator?->update(['user_id' => $operator->id]);
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/trips/{$going->id}", ['action' => 'depart'])
            ->assertOk()
            ->assertJsonPath('data.actual_departure_label', '4:10 PM');

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/trips/{$going->id}", ['action' => 'arrive'])
            ->assertOk()
            ->assertJsonPath('data.role', 'returning')
            ->assertJsonPath('data.can_encode_passengers', true);

        $this->assertNull($going->fresh()->arrived_at);
        $this->assertSame(RegularTripStatus::Completed, $going->fresh()->status);

        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/trips/{$return->id}/passengers", [
                'full_name' => 'Origin Rider',
                'age' => 31,
                'gender' => 'Male',
                'contact_number' => '09170000004',
            ])
            ->assertCreated();

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/trips/{$return->id}", ['action' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    /**
     * @return array{0: User, 1: RegularTrip}
     */
    private function firstTrip(): array
    {
        $admin = User::factory()->create();
        $route = $admin->organization?->seedDefaultRoute();
        $going = $this->makeGoing($admin, $route, 'ANGEL', 1, '08:00');
        app(RegularTripRotationService::class)->ensureReturnTrip($going);

        return [$admin, $going];
    }

    private function makeGoing(User $admin, mixed $route, string $code, int $order, string $time): RegularTrip
    {
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => $code,
            'code' => $code,
            'service_type' => 'regular_trip',
        ]);

        return RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => $order,
            'cycle_number' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => $time,
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);
    }
}
