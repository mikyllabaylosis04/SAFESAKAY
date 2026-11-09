<?php

namespace Tests\Feature;

use App\Enums\BoatStatus;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use App\Notifications\BoatStatusReportNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperatorBoatStatusTest extends TestCase
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

    public function test_operator_can_mark_their_boat_under_maintenance(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::Maintenance->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', BoatStatus::Maintenance->value)
            ->assertJsonPath('data.can_update_status', true)
            ->assertJsonPath('message', 'Boat marked under maintenance.');

        $this->assertSame(BoatStatus::Maintenance, $boat->fresh()->status);
    }

    public function test_operator_can_mark_their_boat_unavailable(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::Unavailable->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', BoatStatus::Unavailable->value);

        $this->assertSame(BoatStatus::Unavailable, $boat->fresh()->status);
    }

    public function test_operator_restoring_available_syncs_on_duty_when_a_trip_is_scheduled(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();
        $boat->update(['status' => BoatStatus::Maintenance]);

        RegularTrip::query()->create([
            'organization_id' => $operator->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-09-19',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Scheduled,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::Available->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', BoatStatus::OnDuty->value);

        $this->assertSame(BoatStatus::OnDuty, $boat->fresh()->status);
    }

    public function test_operator_cannot_set_on_duty_or_on_trip(): void
    {
        [$operator, $boat] = $this->linkedOperatorBoat();

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::OnDuty->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(BoatStatus::Available, $boat->fresh()->status);
    }

    public function test_operator_cannot_update_another_operators_boat(): void
    {
        [$operator] = $this->linkedOperatorBoat();
        $otherBoat = Boat::factory()->create([
            'organization_id' => $operator->organization_id,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$otherBoat->id}/status", [
                'status' => BoatStatus::Maintenance->value,
            ])
            ->assertForbidden();

        $this->assertSame(BoatStatus::Available, $otherBoat->fresh()->status);
    }

    public function test_operator_status_update_notifies_organization_admin(): void
    {
        Notification::fake();

        [$operator, $boat] = $this->linkedOperatorBoat();
        $admin = User::factory()->create([
            'organization_id' => $operator->organization_id,
            'role' => UserRole::Administrator,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::Maintenance->value,
            ])
            ->assertOk();

        Notification::assertSentTo($admin, BoatStatusReportNotification::class);
    }

    public function test_passenger_cannot_update_boat_status(): void
    {
        [, $boat] = $this->linkedOperatorBoat();
        $passenger = User::factory()->create([
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->patchJson("/api/boats/{$boat->id}/status", [
                'status' => BoatStatus::Unavailable->value,
            ])
            ->assertForbidden();
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
        ]);

        $boat->operator?->update(['user_id' => $operator->id]);

        return [$operator, $boat->fresh(['operator'])];
    }
}
