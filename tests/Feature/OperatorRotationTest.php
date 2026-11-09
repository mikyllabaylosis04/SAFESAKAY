<?php

namespace Tests\Feature;

use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperatorRotationTest extends TestCase
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

    public function test_operator_me_shows_their_going_and_return_assignment(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();
        $this->schedulePair($operator, $boat);

        $other = Boat::factory()->create([
            'organization_id' => $operator->organization_id,
        ]);
        $this->schedulePair($operator, $other, order: 2, time: '08:00');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.rotation.boat.id', $boat->id)
            ->assertJsonPath('user.rotation.going.role', 'going')
            ->assertJsonPath('user.rotation.going.boat.id', $boat->id)
            ->assertJsonPath('user.rotation.going.has_departure_time', false)
            ->assertJsonPath('user.rotation.going.departure_time_label', 'Leave When Ready')
            ->assertJsonPath('user.rotation.returning.role', 'returning')
            ->assertJsonPath('user.rotation.returning.has_departure_time', false)
            ->assertJsonPath('user.rotation.returning.departure_time_label', 'No set time')
            ->assertJsonMissing(['user.rotation.going.boat.id' => $other->id]);
    }

    public function test_operator_trips_list_only_includes_their_boat(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();
        $this->schedulePair($operator, $boat);

        $other = Boat::factory()->create([
            'organization_id' => $operator->organization_id,
        ]);
        $this->schedulePair($operator, $other, order: 2, time: '08:00');

        $trips = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/trips')
            ->assertOk()
            ->assertJsonPath('today.boat.id', $boat->id)
            ->assertJsonPath('today.going.boat.id', $boat->id)
            ->assertJsonPath('today.returning.role', 'returning')
            ->json('data');

        $this->assertNotEmpty($trips);
        $this->assertSame(
            [$boat->id],
            collect($trips)->pluck('boat.id')->unique()->values()->all(),
        );
        $this->assertNotContains($other->id, collect($trips)->pluck('boat.id'));
    }

    public function test_operator_bookings_include_regular_trips_for_the_dashboard(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();
        $this->schedulePair($operator, $boat);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'regular_trip')
            ->assertJsonPath('data.0.role', 'going')
            ->assertJsonPath('data.0.boat.id', $boat->id)
            ->assertJsonPath('data.1.kind', 'regular_trip')
            ->assertJsonPath('data.1.role', 'returning')
            ->assertJsonPath('data.0.start_time_label', 'Leave When Ready')
            ->assertJsonPath('data.1.start_time_label', 'No set time')
            ->assertJsonPath('data.0.start_time', '')
            ->assertJsonPath('data.1.start_time', '');
    }

    public function test_passenger_trips_do_not_include_operator_today_block(): void
    {
        $passenger = User::factory()->create([
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/trips')
            ->assertOk()
            ->assertJsonMissingPath('today');

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.rotation', null);
    }

    /**
     * @return array{0: User, 1: Boat}
     */
    private function linkedOperatorBoat(): array
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);

        $boat = Boat::factory()->create([
            'organization_id' => $operator->organization_id,
            'status' => BoatStatus::Available,
            'name' => 'CAIX',
            'code' => 'PB-001',
        ]);

        $boat->operator?->update(['user_id' => $operator->id]);

        return [$operator->fresh(), $boat->fresh(['operator'])];
    }

    private function schedulePair(User $user, Boat $boat, int $order = 1, string $time = '06:00'): void
    {
        $route = $user->organization?->seedDefaultRoute();

        RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => $order,
            'trip_date' => '2026-09-15',
            'departure_time' => $time,
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'is_auto_generated' => false,
        ]);

        RegularTrip::query()->create([
            'organization_id' => $user->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->destination ?? 'Placer').' → '.($route?->origin ?? 'Lakandula'),
            'rotation_order' => $order,
            'trip_date' => '2026-09-15',
            'departure_time' => '07:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
            'is_auto_generated' => false,
        ]);
    }
}
