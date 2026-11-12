<?php

namespace Tests\Feature;

use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\RegularTripRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularTripLeaveOrderCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_completed_cycle_keeps_its_own_return_record(): void
    {
        $user = User::factory()->create();
        [$first] = $this->threeBoatLineup($user);

        $this->runCycle($user, $first);
        $next = $this->waitingTrip($first->boat_id);
        $this->runCycle($user, $next);

        $returns = RegularTrip::query()
            ->where('boat_id', $first->boat_id)
            ->get()
            ->reject(fn (RegularTrip $trip) => $trip->isOutbound())
            ->filter(fn (RegularTrip $trip) => $trip->status === RegularTripStatus::Completed);

        $this->assertGreaterThanOrEqual(2, $returns->count());
        $this->assertCount($returns->count(), $returns->pluck('id')->unique());
    }

    public function test_departing_first_boat_leaves_the_waiting_queue(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->threeBoatLineup($user);

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->assertSame(RegularTripStatus::OnTrip, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->departed_at);
        $this->assertSame(1, $first->fresh()->rotation_order);
        $this->assertSame(2, $second->fresh()->rotation_order);
        $this->assertSame(3, $third->fresh()->rotation_order);

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_completed_trip_returns_to_the_last_waiting_position(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->threeBoatLineup($user);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $return = RegularTrip::query()->where('return_of_id', $first->id)->first();
        $this->assertNotNull($return);

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ])
            ->assertRedirect(route('regular-trips.show', ['regularTrip' => $return, 'encode' => 1]));

        $this->assertSame(RegularTripStatus::Completed, $first->fresh()->status);
        $this->assertNull($first->fresh()->arrived_at);
        $this->assertSame(RegularTripStatus::Scheduled, $return->fresh()->status);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => true,
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));
        $this->assertNotNull($first->fresh()->completed_at);

        $waiting = RegularTrip::query()
            ->whereDate('trip_date', now()->toDateString())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->sortBy(fn (RegularTrip $trip) => (int) ($trip->queue_order ?: $trip->rotation_order))
            ->values();

        $this->assertSame(
            [$second->boat_id, $third->boat_id, $first->boat_id],
            $waiting->pluck('boat_id')->all(),
        );
        $this->assertSame([2, 3, 1], $waiting->pluck('rotation_order')->all());
        $this->assertNotSame($first->id, $waiting->last()->id);
    }

    public function test_return_depart_locks_the_manifest_and_can_complete_without_duplicate(): void
    {
        $user = User::factory()->create();
        [$first] = $this->threeBoatLineup($user);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
            ]);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ]);

        $return = RegularTrip::query()->where('return_of_id', $first->id)->firstOrFail();

        $this->assertNull($return->fresh()->boarding_started_at);
        $this->assertTrue($return->fresh()->canEncodePassengers());

        $this->actingAs($user)
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Return Rider',
                'age' => 29,
                'gender' => 'Female',
                'contact_number' => '09170000009',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => true,
            ])
            ->assertRedirect();

        $return->refresh();
        $this->assertSame(RegularTripStatus::Completed, $return->status);
        $this->assertFalse($return->canEncodePassengers());

        $this->actingAs($user)
            ->from(route('regular-trips.show', $return))
            ->post(route('regular-trips.passengers.store', $return), [
                'full_name' => 'Late Return Rider',
                'age' => 33,
                'gender' => 'Male',
                'contact_number' => '09170000010',
            ])
            ->assertSessionHasErrors('full_name');

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => true,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => true,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_same_boat_can_complete_multiple_trips_and_trips_today_increases(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->threeBoatLineup($user);

        $this->runCycle($user, $first);
        $rejoined = $this->waitingTrip($first->boat_id);
        $this->runCycle($user, $rejoined);
        $again = $this->waitingTrip($first->boat_id);
        $this->runCycle($user, $again);

        $completed = RegularTrip::query()
            ->where('boat_id', $first->boat_id)
            ->where('status', RegularTripStatus::Completed)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertCount(3, $completed);
        $this->assertTrue($second->fresh()->status->isWaiting());
        $this->assertTrue($third->fresh()->status->isWaiting());
    }

    public function test_off_duty_boat_is_excluded_then_rejoins_last(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->threeBoatLineup($user);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::OffDuty->value,
            ])
            ->assertRedirect();

        $this->assertSame(RegularTripStatus::OffDuty, $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->rotation_order);
        $this->assertSame(2, $second->fresh()->rotation_order);
        $this->assertSame(3, $third->fresh()->rotation_order);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $first), [
                'status' => RegularTripStatus::Scheduled->value,
            ])
            ->assertRedirect();

        $this->assertSame(RegularTripStatus::Scheduled, $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->rotation_order);
        $this->assertSame(2, $second->fresh()->rotation_order);
        $this->assertSame(3, $third->fresh()->rotation_order);
        $this->assertGreaterThan(
            (int) ($third->fresh()->queue_order ?: $third->fresh()->rotation_order),
            (int) $first->fresh()->queue_order,
        );
    }

    public function test_complete_without_rejoin_sets_off_duty(): void
    {
        $user = User::factory()->create();
        [$first] = $this->threeBoatLineup($user);

        $this->runCycle($user, $first, returnToQueue: false);

        $offDuty = RegularTrip::query()
            ->where('boat_id', $first->boat_id)
            ->where('status', RegularTripStatus::OffDuty)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertNotNull($offDuty);
        $this->assertSame(RegularTripStatus::Completed, $first->fresh()->status);
    }

    public function test_original_rotation_numbers_stay_fixed_while_rows_move(): void
    {
        $user = User::factory()->create();
        [$amihan, $habagat, $bagwis, $silangan] = $this->fourBoatLineup($user);

        $this->depart($user, $amihan);
        $this->depart($user, $habagat);

        $this->assertSame([1, 2, 3, 4], [
            $amihan->fresh()->rotation_order,
            $habagat->fresh()->rotation_order,
            $bagwis->fresh()->rotation_order,
            $silangan->fresh()->rotation_order,
        ]);

        $this->finishReturn($user, $amihan, returnToQueue: true);

        $waiting = $this->waitingBoats();
        $this->assertSame(
            [$bagwis->boat_id, $silangan->boat_id, $amihan->boat_id],
            $waiting->pluck('boat_id')->all(),
        );
        $this->assertSame([3, 4, 1], $waiting->pluck('rotation_order')->all());
        $this->assertSame(RegularTripStatus::OnTrip, $habagat->fresh()->status);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $silangan), [
                'status' => RegularTripStatus::OffDuty->value,
                'duty_note' => 'off_duty',
            ])
            ->assertRedirect();

        $this->assertSame(4, $silangan->fresh()->rotation_order);
        $this->assertSame(RegularTripStatus::OffDuty, $silangan->fresh()->status);

        $this->finishReturn($user, $habagat, returnToQueue: false);

        $this->assertSame(2, $habagat->fresh()->rotation_order);
        $ended = RegularTrip::query()
            ->where('boat_id', $habagat->boat_id)
            ->where('status', RegularTripStatus::OffDuty)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());
        $this->assertNotNull($ended);
        $this->assertSame(2, $ended->rotation_order);
        $this->assertSame('completed_today', $ended->remarks);
    }

    public function test_back_to_rotation_clears_the_completed_mark_so_passengers_can_be_added(): void
    {
        $user = User::factory()->create();
        [$trip] = $this->fourBoatLineup($user);
        $trip->update([
            'status' => RegularTripStatus::OffDuty,
            'remarks' => 'completed_today',
            'queue_order' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::Scheduled->value,
            ])
            ->assertRedirect();

        $trip->refresh();
        $this->assertSame(RegularTripStatus::Scheduled, $trip->status);
        $this->assertNull($trip->remarks);
        $this->assertTrue($trip->canEncodePassengers());
    }

    /**
     * @return array{0: RegularTrip, 1: RegularTrip, 2: RegularTrip, 3: RegularTrip}
     */
    private function fourBoatLineup(User $user): array
    {
        $route = $user->organization?->seedDefaultRoute();
        $boats = Boat::factory()->count(4)->create([
            'organization_id' => $user->organization_id,
        ]);
        $rotation = app(RegularTripRotationService::class);
        $trips = [];

        foreach ($boats->values() as $index => $boat) {
            $trip = $this->makeGoingTrip($user, $boat, $index + 1, sprintf('%02d:00', 6 + $index), $route);
            $rotation->ensureReturnTrip($trip);
            $trips[] = $trip;
        }

        return $trips;
    }

    private function depart(User $user, RegularTrip $trip): void
    {
        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();
    }

    private function finishReturn(User $user, RegularTrip $going, bool $returnToQueue): void
    {
        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ])
            ->assertRedirect();

        $return = RegularTrip::query()->where('return_of_id', $going->id)->firstOrFail();

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => $returnToQueue,
            ])
            ->assertRedirect();
    }

    private function waitingBoats()
    {
        return RegularTrip::query()
            ->whereDate('trip_date', now()->toDateString())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->sortBy(fn (RegularTrip $trip) => (int) ($trip->queue_order ?: $trip->rotation_order))
            ->values();
    }

    /**
     * @return array{0: RegularTrip, 1: RegularTrip, 2: RegularTrip}
     */
    private function threeBoatLineup(User $user): array
    {
        $route = $user->organization?->seedDefaultRoute();
        $boats = Boat::factory()->count(3)->create([
            'organization_id' => $user->organization_id,
        ]);

        $rotation = app(RegularTripRotationService::class);
        $trips = [];

        foreach ($boats->values() as $index => $boat) {
            $trip = $this->makeGoingTrip(
                $user,
                $boat,
                $index + 1,
                sprintf('%02d:00', 6 + $index),
                $route,
            );
            $return = $rotation->ensureReturnTrip($trip);
            $this->assertNotNull($return, 'Expected a return slot for going order '.($index + 1));
            $trips[] = $trip;
        }

        return $trips;
    }

    private function runCycle(User $user, RegularTrip $trip, bool $returnToQueue = true): void
    {
        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'arrive',
            ])
            ->assertRedirect();

        $return = RegularTrip::query()
            ->where('return_of_id', $trip->id)
            ->first()
            ?? RegularTrip::query()
                ->where('boat_id', $trip->boat_id)
                ->whereDate('trip_date', $trip->trip_date)
                ->get()
                ->first(fn (RegularTrip $item) => ! $item->isOutbound() && $item->status !== RegularTripStatus::Completed);

        $this->assertNotNull($return);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => $returnToQueue,
            ])
            ->assertRedirect();
    }

    private function waitingTrip(int $boatId): RegularTrip
    {
        return RegularTrip::query()
            ->where('boat_id', $boatId)
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound())
            ?? RegularTrip::query()->where('boat_id', $boatId)->latest('id')->firstOrFail();
    }

    private function makeGoingTrip(
        User $user,
        Boat $boat,
        int $order,
        string $time,
        ?OrganizationRoute $route = null,
    ): RegularTrip {
        $route ??= $user->organization?->seedDefaultRoute();
        $origin = $route?->origin ?? 'Lakandula';
        $destination = $route?->destination ?? 'Placer';

        return RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? ($origin.' ↔ '.$destination),
            'direction' => $origin.' → '.$destination,
            'rotation_order' => $order,
            'trip_date' => now()->toDateString(),
            'departure_time' => $time,
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'is_auto_generated' => false,
        ]);
    }
}
