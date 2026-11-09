<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileOrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_operator_can_access_operational_endpoints(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'username' => 'active.operator',
        ]);

        $login = $this->postJson('/api/login', [
            'username' => 'active.operator',
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.organization.status', 'active')
            ->assertJsonPath('user.organization.allows_operations', true);

        $this->withToken($login->json('token'))
            ->getJson('/api/trips')
            ->assertOk();
    }

    public function test_archived_operator_can_log_in_but_cannot_use_operations(): void
    {
        [$organization, $operator] = $this->archivedOperator('Barangay Lakandula');

        $login = $this->postJson('/api/login', [
            'username' => $operator->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.organization.name', 'Barangay Lakandula')
            ->assertJsonPath('user.organization.status', 'archived')
            ->assertJsonPath('user.organization.allows_operations', false);

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.organization.status', 'archived');

        $this->withToken($token)
            ->getJson('/api/bookings')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/trips')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_INACTIVE')
            ->assertJsonPath('organization_status', 'archived')
            ->assertJsonPath('organization_name', 'Barangay Lakandula')
            ->assertJsonPath('message', 'This organization is no longer active on SafeSakay.');

        $this->withToken($token)
            ->patchJson('/api/boats/1/status', ['status' => 'available'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_INACTIVE');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Barangay Lakandula',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $operator->id,
            'is_active' => true,
        ]);
    }

    public function test_suspended_operator_sees_inactive_api_status(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Sanisani',
            'is_active' => false,
            'inactive_kind' => 'suspended',
        ]);
        $operator = User::factory()->for($organization)->create([
            'role' => UserRole::Operator,
            'username' => 'suspended.operator',
        ]);

        $login = $this->postJson('/api/login', [
            'username' => 'suspended.operator',
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/trips')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_INACTIVE')
            ->assertJsonPath('organization_status', 'suspended');
    }

    public function test_archived_passenger_cannot_book_but_can_read_history_and_switch_org(): void
    {
        [$archived, $passenger, $archivedBoat] = $this->archivedPassenger('Barangay Lakandula');
        $active = Organization::factory()->create([
            'name' => 'Barangay Del Carmen',
            'is_active' => true,
            'approved_at' => now(),
        ]);
        Boat::factory()->create([
            'organization_id' => $active->id,
            'is_active' => true,
        ]);
        $history = BoatBooking::query()->create([
            'organization_id' => $archived->id,
            'user_id' => $passenger->id,
            'boat_id' => $archivedBoat->id,
            'code' => 'BR-2026-08001',
            'customer_name' => $passenger->name,
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'pickup_point' => 'Lakandula Port',
            'booking_date' => '2026-09-24',
            'start_time' => '08:00',
            'passenger_count' => 3,
            'status' => 'pending',
        ]);

        $login = $this->postJson('/api/login', [
            'username' => $passenger->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.organization.status', 'archived');

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $history->id);

        $this->withToken($token)
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)
            ->postJson('/api/bookings', ['boat_id' => $archivedBoat->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_INACTIVE')
            ->assertJsonPath('organization_name', 'Barangay Lakandula');

        $this->withToken($token)
            ->patchJson('/api/me/service-area', ['organization_id' => $active->id])
            ->assertOk()
            ->assertJsonPath('user.organization.id', $active->id)
            ->assertJsonPath('user.organization.status', 'active');

        $this->withToken($token)
            ->getJson('/api/bookings/'.$history->id)
            ->assertOk()
            ->assertJsonPath('data.id', $history->id);

        $this->withToken($token)
            ->postJson('/api/bookings', ['boat_id' => $archivedBoat->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_INACTIVE');
    }

    public function test_reactivated_organization_restores_operator_access(): void
    {
        [$organization, $operator] = $this->archivedOperator('Barangay Lakandula');

        $login = $this->postJson('/api/login', [
            'username' => $operator->username,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/trips')
            ->assertForbidden();

        $operatorOrg = Organization::query()->findOrFail($operator->organization_id);
        $operatorOrg->restoreAccess();

        $this->assertTrue($operatorOrg->fresh()->providesOperations());

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.organization.id', $operatorOrg->id)
            ->assertJsonPath('user.organization.status', 'active')
            ->assertJsonPath('user.organization.allows_operations', true);

        $this->withToken($token)
            ->getJson('/api/trips')
            ->assertOk();
    }

    public function test_active_organization_is_unaffected_when_another_is_archived(): void
    {
        $this->archivedOperator('Barangay Lakandula');

        $activeOperator = User::factory()->create([
            'role' => UserRole::Operator,
            'username' => 'other.operator',
        ]);

        $login = $this->postJson('/api/login', [
            'username' => 'other.operator',
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/trips')
            ->assertOk();

        $this->assertTrue($activeOperator->organization->fresh()->providesOperations());
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function archivedOperator(string $name): array
    {
        $organization = Organization::factory()->create([
            'name' => $name,
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $operator = User::factory()->for($organization)->create([
            'role' => UserRole::Operator,
            'username' => 'archived.operator',
            'email' => 'archived.operator@safesakay.test',
        ]);

        $organization->update([
            'is_active' => false,
            'inactive_kind' => 'archived',
        ]);

        return [$organization->fresh(), $operator->fresh()];
    }

    /**
     * @return array{0: Organization, 1: User, 2: Boat}
     */
    private function archivedPassenger(string $name): array
    {
        $organization = Organization::factory()->create([
            'name' => $name,
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $passenger = User::factory()->for($organization)->create([
            'role' => UserRole::Passenger,
            'email' => 'archived.passenger@safesakay.test',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $organization->update([
            'is_active' => false,
            'inactive_kind' => 'archived',
        ]);

        return [$organization->fresh(), $passenger->fresh(), $boat->fresh()];
    }
}
