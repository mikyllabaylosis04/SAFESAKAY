<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegularTripRotationAdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_visiting_rotation_page_creates_tomorrow_with_boats_rotated(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->lineup($user);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/RegularTripRotation')
                ->where('trips.0.is_outbound', true)
                ->where('trips.0.heading_label', fn ($label) => is_string($label) && str_starts_with($label, 'To '))
                ->has('ports.origin')
                ->has('ports.destination'));

        $tomorrow = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-16')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();

        $outbound = $this->outbound($tomorrow);
        $returns = $this->returns($tomorrow);

        $this->assertCount(6, $tomorrow);
        $this->assertTrue($tomorrow->every(fn (RegularTrip $trip) => $trip->is_auto_generated));
        $this->assertSame([$second->id, $third->id, $first->id], $outbound->pluck('boat_id')->all());
        $this->assertSame([$second->id, $third->id, $first->id], $returns->pluck('boat_id')->all());
        $this->assertSame(['06:00', '08:00', '10:00'], $outbound->map(
            fn (RegularTrip $trip) => Carbon::parse($trip->departure_time)->format('H:i'),
        )->all());
        $this->assertSame(['07:00', '09:00', '11:00'], $returns->map(
            fn (RegularTrip $trip) => Carbon::parse($trip->departure_time)->format('H:i'),
        )->all());
        $this->assertSame(
            $outbound->map(fn (RegularTrip $trip) => RegularTrip::reverseDirection($trip->direction))->all(),
            $returns->pluck('direction')->all(),
        );
    }

    public function test_automatic_rotation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->lineup($user);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertSame(6, RegularTrip::query()->whereDate('trip_date', '2026-09-16')->count());
    }

    public function test_ineligible_boats_are_skipped_on_the_next_day(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->lineup($user);
        $third->update(['status' => BoatStatus::Maintenance]);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $tomorrow = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-16')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();

        $this->assertSame([$second->id, $first->id], $this->outbound($tomorrow)->pluck('boat_id')->all());
        $this->assertSame([$second->id, $first->id], $this->returns($tomorrow)->pluck('boat_id')->all());
    }

    public function test_boats_with_a_confirmed_booking_are_skipped_on_that_day(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = $this->lineup($user);

        BoatBooking::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $third->id,
            'code' => 'BR-2026-00916',
            'customer_name' => 'Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-16',
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $tomorrow = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-16')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();

        $this->assertSame([$second->id, $first->id], $this->outbound($tomorrow)->pluck('boat_id')->all());
        $this->assertSame([$second->id, $first->id], $this->returns($tomorrow)->pluck('boat_id')->all());
    }

    public function test_manual_future_trips_are_not_replaced(): void
    {
        $user = User::factory()->create();
        [$first] = $this->lineup($user, boats: 1);
        $route = $user->organization?->seedDefaultRoute();

        $manual = $this->scheduleTrip($user, $first, '2026-09-16', '07:00', 1, $route);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $tomorrow = RegularTrip::query()->whereDate('trip_date', '2026-09-16')->get();
        $outbound = $this->outbound($tomorrow);

        $this->assertCount(1, $outbound);
        $this->assertSame($manual->id, $outbound->first()->id);
        $this->assertCount(1, $this->returns($tomorrow));
        $this->assertSame($first->id, $this->returns($tomorrow)->first()->boat_id);
        $this->assertFalse($manual->fresh()->is_auto_generated);
        $this->assertSame('07:00', Carbon::parse($manual->fresh()->departure_time)->format('H:i'));
    }

    public function test_adding_a_trip_today_refreshes_automatic_tomorrow(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        [$first, $second, $third] = $this->makeBoats($user, 3);

        $this->scheduleTrip($user, $first, '2026-09-15', '06:00', 1, $route);
        $this->scheduleTrip($user, $second, '2026-09-15', '08:00', 2, $route);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertSame(4, RegularTrip::query()->whereDate('trip_date', '2026-09-16')->count());

        $this->actingAs($user)
            ->post(route('regular-trips.store'), [
                'boat_id' => $third->id,
                'route' => $route?->name,
                'direction' => $route?->origin.' → '.$route?->destination,
                'trip_date' => '2026-09-15',
                'departure_time' => '10:00',
                'rotation_order' => 3,
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $tomorrow = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-16')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();

        $this->assertSame([$second->id, $third->id, $first->id], $this->outbound($tomorrow)->pluck('boat_id')->all());
        $this->assertSame([$second->id, $third->id, $first->id], $this->returns($tomorrow)->pluck('boat_id')->all());
    }

    public function test_rotation_does_not_copy_another_organization_lineup(): void
    {
        $ours = User::factory()->create();
        $theirs = User::factory()->create();
        $this->lineup($theirs);

        $this->actingAs($ours)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertSame(0, RegularTrip::query()->count());
    }

    public function test_dashboard_also_creates_the_next_days(): void
    {
        $user = User::factory()->create();
        $this->lineup($user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame(6, RegularTrip::query()->whereDate('trip_date', '2026-09-16')->count());
    }

    public function test_advance_command_creates_the_next_days(): void
    {
        $user = User::factory()->create();
        $this->lineup($user);

        $this->artisan('rotation:advance')
            ->assertSuccessful();

        $this->assertSame(6, RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $user->organization_id)
            ->whereDate('trip_date', '2026-09-16')
            ->count());
    }

    public function test_adding_a_boat_uses_leave_order_without_a_time(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        [$first, $second] = $this->makeBoats($user, 2);

        $this->actingAs($user)
            ->post(route('regular-trips.store'), [
                'boat_id' => $first->id,
                'route' => $route?->name,
                'trip_date' => '2026-09-15',
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $this->actingAs($user)
            ->post(route('regular-trips.store'), [
                'boat_id' => $second->id,
                'route' => $route?->name,
                'trip_date' => '2026-09-15',
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $today = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-15')
            ->orderBy('rotation_order')
            ->orderBy('departure_time')
            ->get();

        $outbound = $this->outbound($today);
        $returns = $this->returns($today);

        $this->assertCount(4, $today);
        $this->assertSame([1, 2], $outbound->pluck('rotation_order')->all());
        $this->assertSame([$first->id, $second->id], $outbound->pluck('boat_id')->all());
        $this->assertSame([$first->id, $second->id], $returns->pluck('boat_id')->all());
        $this->assertSame(['06:00', '08:00'], $outbound->map(
            fn (RegularTrip $trip) => Carbon::parse($trip->departure_time)->format('H:i'),
        )->all());
        $this->assertSame(['07:00', '09:00'], $returns->map(
            fn (RegularTrip $trip) => Carbon::parse($trip->departure_time)->format('H:i'),
        )->all());
        $this->assertSame(
            RegularTrip::reverseDirection($outbound[0]->direction),
            $returns[0]->direction,
        );
    }

    public function test_adding_an_outbound_trip_also_creates_the_return(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        [$boat] = $this->makeBoats($user, 1);

        $this->actingAs($user)
            ->post(route('regular-trips.store'), [
                'boat_id' => $boat->id,
                'route' => $route?->name,
                'trip_date' => '2026-09-15',
                'departure_time' => '06:00',
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $today = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-15')
            ->orderBy('departure_time')
            ->get();

        $this->assertCount(2, $today);
        $this->assertTrue($today[0]->isOutbound());
        $this->assertFalse($today[1]->isOutbound());
        $this->assertSame($boat->id, $today[0]->boat_id);
        $this->assertSame($boat->id, $today[1]->boat_id);
        $this->assertSame('06:00', Carbon::parse($today[0]->departure_time)->format('H:i'));
        $this->assertSame('07:00', Carbon::parse($today[1]->departure_time)->format('H:i'));
        $this->assertSame(
            RegularTrip::reverseDirection($today[0]->direction),
            $today[1]->direction,
        );

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips', function ($trips) {
                    $rows = collect($trips);
                    $going = $rows->firstWhere('is_outbound', true);
                    $returning = $rows->firstWhere('is_outbound', false);

                    return is_array($going)
                        && $going['departure_time_label'] === 'Leave When Ready'
                        && is_array($returning)
                        && $returning['departure_time_label'] === 'No set time';
                }));
    }

    public function test_deleting_one_trip_removes_the_return_and_updates_the_next_days(): void
    {
        $user = User::factory()->create();
        [$first, $second] = $this->lineup($user, 2);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertTrue(
            RegularTrip::query()
                ->whereDate('trip_date', '2026-09-16')
                ->where('boat_id', $first->id)
                ->exists(),
        );

        $trip = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-15')
            ->where('boat_id', $first->id)
            ->where('rotation_order', 1)
            ->firstOrFail();

        $this->actingAs($user)
            ->delete(route('regular-trips.destroy', $trip))
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $this->assertSame(
            0,
            RegularTrip::query()
                ->whereDate('trip_date', '2026-09-15')
                ->where('boat_id', $first->id)
                ->count(),
        );
        $this->assertSame(
            0,
            RegularTrip::query()
                ->whereDate('trip_date', '2026-09-16')
                ->where('boat_id', $first->id)
                ->count(),
        );
        $this->assertTrue(
            RegularTrip::query()
                ->whereDate('trip_date', '2026-09-15')
                ->where('boat_id', $second->id)
                ->exists(),
        );
    }

    public function test_deleting_all_trips_today_does_not_bring_them_back(): void
    {
        $user = User::factory()->create();
        $this->lineup($user, 1);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertTrue(
            RegularTrip::query()->whereDate('trip_date', '2026-09-16')->exists(),
        );

        RegularTrip::query()
            ->whereDate('trip_date', '2026-09-15')
            ->get()
            ->each(fn (RegularTrip $trip) => $this->actingAs($user)
                ->delete(route('regular-trips.destroy', $trip)));

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertSame(0, RegularTrip::query()->whereDate('trip_date', '2026-09-15')->count());
        $this->assertSame(0, RegularTrip::query()->whereDate('trip_date', '2026-09-16')->count());
    }

    public function test_rush_going_trip_has_no_displayed_time(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        [$boat] = $this->makeBoats($user, 1);

        $this->actingAs($user)
            ->post(route('regular-trips.store'), [
                'boat_id' => $boat->id,
                'route' => $route?->name,
                'trip_date' => '2026-09-15',
                'schedule_mode' => 'rush',
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $going = RegularTrip::query()
            ->whereDate('trip_date', '2026-09-15')
            ->get()
            ->first(fn (RegularTrip $trip) => $trip->isOutbound());

        $this->assertTrue($going?->isRushSchedule());
        $this->assertFalse($going?->hasScheduledTime());

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips', function ($trips) {
                    $rows = collect($trips);

                    $going = $rows->firstWhere('is_outbound', true);
                    $returning = $rows->firstWhere('is_outbound', false);

                    return is_array($going)
                        && $going['departure_time_label'] === 'Leave When Ready'
                        && is_array($returning)
                        && $returning['departure_time_label'] === 'No set time';
                }));
    }

    public function test_day_can_switch_to_rush_and_next_days_follow(): void
    {
        $user = User::factory()->create();
        $this->lineup($user, 2);

        $this->actingAs($user)
            ->patch(route('regular-trips.schedule-mode'), [
                'trip_date' => '2026-09-15',
                'schedule_mode' => 'rush',
            ])
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $today = RegularTrip::query()->whereDate('trip_date', '2026-09-15')->get();
        $tomorrow = RegularTrip::query()->whereDate('trip_date', '2026-09-16')->get();

        $this->assertTrue($this->outbound($today)->every(fn (RegularTrip $trip) => $trip->isRushSchedule()));
        $this->assertTrue($this->outbound($tomorrow)->every(fn (RegularTrip $trip) => $trip->isRushSchedule()));
        $this->assertTrue($this->outbound($today)->every(fn (RegularTrip $trip) => ! $trip->hasScheduledTime()));
    }

    public function test_admin_can_delete_a_trip_from_the_rotation(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        [$boat] = $this->makeBoats($user, 1);
        $trip = $this->scheduleTrip($user, $boat, '2026-09-15', '06:00', 1, $route);

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->delete(route('regular-trips.destroy', $trip))
            ->assertRedirect(route('schedules.regular-trip-rotation'));

        $this->assertDatabaseMissing('regular_trips', [
            'id' => $trip->id,
        ]);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk();

        $this->assertDatabaseMissing('regular_trips', [
            'id' => $trip->id,
        ]);
    }

    /**
     * @param  Collection<int, RegularTrip>  $trips
     * @return Collection<int, RegularTrip>
     */
    private function outbound($trips)
    {
        return $trips->filter(fn (RegularTrip $trip) => $trip->isOutbound())->values();
    }

    /**
     * @param  Collection<int, RegularTrip>  $trips
     * @return Collection<int, RegularTrip>
     */
    private function returns($trips)
    {
        return $trips->reject(fn (RegularTrip $trip) => $trip->isOutbound())->values();
    }

    /**
     * @return array<int, Boat>
     */
    private function lineup(User $user, int $boats = 3): array
    {
        $route = $user->organization?->seedDefaultRoute();
        $created = $this->makeBoats($user, $boats);
        $times = ['06:00', '08:00', '10:00'];

        foreach ($created as $index => $boat) {
            $this->scheduleTrip($user, $boat, '2026-09-15', $times[$index], $index + 1, $route);
        }

        return $created;
    }

    /**
     * @return array<int, Boat>
     */
    private function makeBoats(User $user, int $count): array
    {
        $boats = [];

        for ($i = 1; $i <= $count; $i++) {
            $boats[] = Boat::factory()->create([
                'organization_id' => $user->organization_id,
                'name' => 'Boat '.$i,
                'code' => 'ROT-'.$i,
            ]);
        }

        return $boats;
    }

    private function scheduleTrip(
        User $user,
        Boat $boat,
        string $date,
        string $time,
        int $order,
        ?OrganizationRoute $route = null,
    ): RegularTrip {
        $route ??= $user->organization?->seedDefaultRoute();

        return RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => $order,
            'trip_date' => $date,
            'departure_time' => $time,
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'is_auto_generated' => false,
        ]);
    }
}
