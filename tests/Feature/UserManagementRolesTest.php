<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_shows_user_operator_and_administrator_roles(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);

        User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::User,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/Index')
                ->where('stats.total', 3)
                ->where('stats.administrators', 1)
                ->where('stats.operators', 1)
                ->where('stats.inactive', 1)
                ->where('roleOptions.0.value', UserRole::Administrator->value)
                ->where('roleOptions.0.label', 'Admin')
                ->where('roleOptions.1.value', UserRole::User->value)
                ->where('roleOptions.1.label', 'User')
                ->where('roleOptions.2.value', UserRole::Operator->value)
                ->where('roleOptions.2.label', 'Operator')
                ->where('roleOptions.2.description', 'Boat operator. Uses the mobile app to manage bookings and boats.')
                ->has('boats')
                ->missing('stats.staff')
                ->where('roleOptions', fn ($roles) => collect($roles)->pluck('value')->doesntContain('staff')));
    }

    public function test_legacy_staff_accounts_count_as_operators(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.operators', 1)
                ->where(
                    'users',
                    fn ($users) => collect($users)->contains(
                        fn (array $user) => $user['role'] === UserRole::Operator->value
                            && $user['role_label'] === 'Operator',
                    ),
                ));
    }

    public function test_admin_can_create_user_and_operator_accounts(): void
    {
        $admin = User::factory()->create();

        foreach ([
            ['email' => 'user.role@example.com', 'role' => UserRole::User->value],
            ['email' => 'operator.role@example.com', 'role' => UserRole::Operator->value],
        ] as $account) {
            $this->actingAs($admin)
                ->from(route('users.index'))
                ->post(route('users.store'), [
                    'name' => 'SafeSakay Account',
                    'email' => $account['email'],
                    'role' => $account['role'],
                    'is_active' => '1',
                    'password' => 'SafeSakay@1',
                    'password_confirmation' => 'SafeSakay@1',
                ])
                ->assertRedirect(route('users.index'));
        }

        $this->assertDatabaseHas('users', [
            'email' => 'user.role@example.com',
            'role' => UserRole::User->value,
            'organization_id' => $admin->organization_id,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'operator.role@example.com',
            'role' => UserRole::Operator->value,
            'organization_id' => $admin->organization_id,
        ]);
        $this->assertNotNull(
            User::query()->where('email', 'operator.role@example.com')->value('username'),
        );
    }

    public function test_staff_role_is_no_longer_accepted(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Old Staff',
                'email' => 'staff@example.com',
                'role' => 'staff',
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_operator_accounts_do_not_need_a_username(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan@safesakay.test',
                'role' => UserRole::Operator->value,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'juan@safesakay.test',
            'role' => UserRole::Operator->value,
        ]);
        $this->assertNotNull(
            User::query()->where('email', 'juan@safesakay.test')->value('username'),
        );
    }

    public function test_passenger_role_cannot_be_assigned_in_user_management(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Passenger',
                'email' => 'passenger@example.com',
                'role' => UserRole::Passenger->value,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_operator_account_can_be_linked_to_a_registered_boat(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'CAIX',
            'code' => 'PB-001',
        ]);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Juan Dela Cruz',
                'username' => 'juan.operator',
                'email' => 'juan.operator@example.com',
                'role' => UserRole::Operator->value,
                'boat_id' => $boat->id,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertRedirect(route('users.index'));

        $operatorUser = User::query()->where('email', 'juan.operator@example.com')->first();

        $this->assertNotNull($operatorUser);
        $this->assertSame($operatorUser->id, $boat->fresh()?->operator?->user_id);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boats.0.id', $boat->id)
                ->where(
                    'users',
                    fn ($users) => collect($users)->contains(
                        fn (array $user) => $user['email'] === 'juan.operator@example.com'
                            && $user['boat_id'] === $boat->id
                            && $user['boat_label'] === 'CAIX (PB-001)',
                    ),
                ));
    }

    public function test_operator_boat_must_belong_to_the_same_organization(): void
    {
        $admin = User::factory()->create();
        $otherBoat = Boat::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Juan Dela Cruz',
                'username' => 'juan.operator',
                'email' => 'juan.operator@example.com',
                'role' => UserRole::Operator->value,
                'boat_id' => $otherBoat->id,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertSessionHasErrors('boat_id');
    }

    public function test_linking_a_boat_moves_it_from_the_previous_operator(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);
        $firstOperator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'first.operator',
        ]);

        $boat->operator?->update(['user_id' => $firstOperator->id]);

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Second Operator',
                'username' => 'second.operator',
                'email' => 'second.operator@example.com',
                'role' => UserRole::Operator->value,
                'boat_id' => $boat->id,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertRedirect(route('users.index'));

        $secondOperator = User::query()->where('email', 'second.operator@example.com')->first();

        $this->assertSame($secondOperator?->id, $boat->fresh()?->operator?->user_id);
        $this->assertTrue($firstOperator->operatorProfiles()->doesntExist());
    }

    public function test_customers_who_selected_this_organization_appear_in_user_management(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $customer = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Santos',
            'email' => 'maria.customer@safesakay.test',
        ]);

        $stranger = User::factory()->create([
            'organization_id' => null,
            'role' => UserRole::Passenger,
            'email' => 'stranger@safesakay.test',
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.customers', 1)
                ->where(
                    'users',
                    fn ($users) => collect($users)->contains(
                        fn (array $user) => $user['id'] === $customer->id
                            && $user['role'] === UserRole::Passenger->value
                            && $user['role_label'] === 'Customer',
                    ) && collect($users)->every(
                        fn (array $user) => $user['id'] !== $stranger->id,
                    ),
                ));
    }

    public function test_customers_who_booked_this_organization_appear_even_without_organization_id(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);
        $customer = User::factory()->create([
            'organization_id' => null,
            'role' => UserRole::Passenger,
            'email' => 'booked.customer@safesakay.test',
        ]);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'user_id' => $customer->id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-01001',
            'customer_name' => $customer->name,
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.customers', 1)
                ->where(
                    'users',
                    fn ($users) => collect($users)->contains(
                        fn (array $user) => $user['email'] === 'booked.customer@safesakay.test'
                            && $user['role_label'] === 'Customer',
                    ),
                ));
    }

    public function test_administrators_from_another_organization_do_not_appear(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
            'email' => 'lakandula.admin@safesakay.test',
        ]);
        $otherAdmin = User::factory()->create([
            'role' => UserRole::Administrator,
            'email' => 'other.barangay.admin@safesakay.test',
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.administrators', 1)
                ->where(
                    'users',
                    fn ($users) => collect($users)->contains(
                        fn (array $user) => $user['email'] === 'lakandula.admin@safesakay.test',
                    ) && collect($users)->every(
                        fn (array $user) => $user['email'] !== 'other.barangay.admin@safesakay.test',
                    ),
                ));
    }

    public function test_admin_can_deactivate_a_customer_for_a_violation(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);
        $customer = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'email' => 'violator@safesakay.test',
        ]);
        $customer->createToken('mobile');

        $this->actingAs($admin)
            ->patch(route('users.status.update', $customer), [
                'is_active' => '0',
                'reason' => 'No-show after confirmed booking',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertFalse($customer->fresh()?->is_active);
        $this->assertSame(
            'No-show after confirmed booking',
            $customer->fresh()?->deactivated_reason,
        );
        $this->assertSame(0, $customer->tokens()->count());

        $this->postJson('/api/login', [
            'username' => $customer->email,
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_admin_can_restore_a_deactivated_customer(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);
        $customer = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'is_active' => false,
            'deactivated_reason' => 'No-show after confirmed booking',
        ]);

        $this->actingAs($admin)
            ->patch(route('users.status.update', $customer), [
                'is_active' => '1',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertTrue($customer->fresh()?->is_active);
        $this->assertNull($customer->fresh()?->deactivated_reason);
    }

    public function test_customer_deactivation_requires_a_reason(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);
        $customer = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->patch(route('users.status.update', $customer), [
                'is_active' => '0',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('reason');

        $this->assertTrue($customer->fresh()?->is_active);
    }

    public function test_customers_from_another_organization_do_not_appear(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);
        $other = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        User::factory()->create([
            'organization_id' => $other->organization_id,
            'role' => UserRole::Passenger,
            'email' => 'other.customer@safesakay.test',
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.customers', 0)
                ->where(
                    'users',
                    fn ($users) => collect($users)->every(
                        fn (array $user) => $user['email'] !== 'other.customer@safesakay.test',
                    ),
                ));
    }
}
