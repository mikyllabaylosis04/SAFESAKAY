<?php

namespace Tests\Feature;

use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\RegularTripRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoatArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_a_boat_out_of_the_active_list(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'CHARMIS',
            'code' => 'PB-003',
            'status' => BoatStatus::Available,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->patch(route('boats.archive', $boat))
            ->assertRedirect(route('boats.index'));

        $boat->refresh();

        $this->assertFalse($boat->is_active);
        $this->assertSame(BoatStatus::Unavailable, $boat->status);
        $this->assertNotSoftDeleted($boat);
    }

    public function test_admin_can_restore_an_archived_boat(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'status' => BoatStatus::Unavailable,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->patch(route('boats.restore', $boat))
            ->assertRedirect(route('boats.index'));

        $boat->refresh();

        $this->assertTrue($boat->is_active);
        $this->assertContains($boat->status, [
            BoatStatus::Available,
            BoatStatus::OnDuty,
            BoatStatus::OnTrip,
        ]);
    }

    public function test_archiving_a_boat_removes_it_from_the_waiting_lineup(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        $archived = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'ANGEL BOMB',
            'code' => 'ANGEL',
            'status' => BoatStatus::OnDuty,
            'is_active' => true,
        ]);
        $active = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'KEYCHAIN',
            'code' => 'KEY',
            'status' => BoatStatus::OnDuty,
            'is_active' => true,
        ]);

        $going = RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $archived->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'cycle_number' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);
        RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $active->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 2,
            'cycle_number' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->actingAs($user)
            ->patch(route('boats.archive', $archived))
            ->assertRedirect();

        $this->assertFalse($archived->fresh()->is_active);
        $this->assertDatabaseHas('regular_trips', [
            'id' => $going->id,
            'status' => RegularTripStatus::Cancelled->value,
            'remarks' => RegularTripRotationService::ARCHIVE_HOLD_REMARK,
        ]);
        $remaining = RegularTrip::query()
            ->where('boat_id', $active->id)
            ->whereDate('trip_date', now())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertSame(2, $remaining?->rotation_order);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('schedules/RegularTripRotation')
                ->where(
                    'trips',
                    function ($trips) {
                        $lineup = collect($trips)->filter(
                            fn ($trip) => ($trip['is_outbound'] ?? false)
                                && in_array($trip['status'] ?? '', ['scheduled', 'delayed', 'on_trip'], true),
                        );

                        return $lineup->every(
                            fn ($trip) => ($trip['boat']['name'] ?? '') !== 'ANGEL BOMB',
                        ) && $lineup->contains(
                            fn ($trip) => ($trip['boat']['name'] ?? '') === 'KEYCHAIN',
                        );
                    },
                ));
    }

    public function test_archiving_compacts_leave_order_and_restore_returns_the_boat_last(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        $first = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'ANGEL BOMB',
            'is_active' => true,
        ]);
        $second = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'KEYCHAIN',
            'is_active' => true,
        ]);
        $third = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'MUNCKIN',
            'is_active' => true,
        ]);

        foreach ([[$first, 1], [$second, 2], [$third, 3]] as [$boat, $order]) {
            RegularTrip::query()->create([
                'organization_id' => $user->organization_id,
                'boat_id' => $boat->id,
                'route' => $route?->name ?? 'Lakandula ↔ Placer',
                'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
                'rotation_order' => $order,
                'cycle_number' => 1,
                'trip_date' => now()->toDateString(),
                'departure_time' => '08:00',
                'passenger_count' => 0,
                'status' => RegularTripStatus::Scheduled,
            ]);
        }

        $this->actingAs($user)
            ->patch(route('boats.archive', $first))
            ->assertRedirect();

        $waiting = RegularTrip::query()
            ->whereDate('trip_date', now())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->sortBy('rotation_order')
            ->values();

        $this->assertSame([$second->id, $third->id], $waiting->pluck('boat_id')->all());
        $this->assertSame([2, 3], $waiting->pluck('rotation_order')->all());

        $this->actingAs($user)
            ->patch(route('boats.restore', $first))
            ->assertRedirect();

        $waiting = RegularTrip::query()
            ->whereDate('trip_date', now())
            ->where('status', RegularTripStatus::Scheduled)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->sortBy(fn (RegularTrip $trip) => (int) ($trip->queue_order ?: $trip->rotation_order))
            ->values();

        $this->assertSame([$second->id, $third->id, $first->id], $waiting->pluck('boat_id')->all());
        $this->assertSame([2, 3, 1], $waiting->pluck('rotation_order')->all());
    }

    public function test_archiving_does_not_rewrite_a_departed_trip(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'status' => BoatStatus::OnTrip,
            'is_active' => true,
        ]);
        $trip = RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'cycle_number' => 1,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::OnTrip,
        ]);

        $this->actingAs($user)
            ->patch(route('boats.archive', $boat))
            ->assertRedirect();

        $this->assertDatabaseHas('regular_trips', [
            'id' => $trip->id,
            'status' => RegularTripStatus::OnTrip->value,
        ]);
    }

    public function test_boat_index_stats_count_only_active_boats(): void
    {
        $user = User::factory()->create();

        Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'status' => BoatStatus::Available,
            'is_active' => true,
        ]);
        Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'status' => BoatStatus::Unavailable,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('boats.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('boats/Index')
                ->where('stats.total', 1)
                ->where('stats.archived', 1)
                ->where('stats.available', 1));
    }
}
