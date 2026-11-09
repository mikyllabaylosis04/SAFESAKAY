<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_register_always_creates_a_passenger_and_ignores_client_role(): void
    {
        $lakandula = \App\Models\Organization::factory()->create([
            'name' => 'Barangay Lakandula',
            'address' => 'Lakandula, Del Carmen, Siargao',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Maria Santos',
            'email' => 'maria@safesakay.test',
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
            'role' => UserRole::Administrator->value,
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', UserRole::Passenger->value)
            ->assertJsonPath('user.email', 'maria@safesakay.test')
            ->assertJsonPath('user.organization.id', $lakandula->id)
            ->assertJsonPath('user.organization.name', 'Barangay Lakandula');

        $this->assertDatabaseHas('users', [
            'email' => 'maria@safesakay.test',
            'organization_id' => $lakandula->id,
            'role' => UserRole::Passenger->value,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'maria@safesakay.test',
            'role' => UserRole::Administrator->value,
        ]);
    }

    public function test_mobile_login_returns_operator_role_and_organization(): void
    {
        $operator = User::factory()->create([
            'name' => 'Juan Dela Cruz',
            'username' => 'juan.operator',
            'email' => 'juan@safesakay.test',
            'role' => UserRole::Operator,
        ]);

        $this->postJson('/api/login', [
            'username' => 'juan.operator',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $operator->id)
            ->assertJsonPath('user.name', 'Juan Dela Cruz')
            ->assertJsonPath('user.username', 'juan.operator')
            ->assertJsonPath('user.email', 'juan@safesakay.test')
            ->assertJsonPath('user.role', 'operator')
            ->assertJsonPath('user.organization.id', $operator->organization_id)
            ->assertJsonPath('user.organization.name', $operator->organization->name)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_mobile_login_accepts_operator_email(): void
    {
        $operator = User::factory()->create([
            'username' => 'juan.operator',
            'email' => 'juan@safesakay.test',
            'role' => UserRole::Operator,
        ]);

        $this->postJson('/api/login', [
            'username' => 'juan@safesakay.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $operator->id)
            ->assertJsonPath('user.role', 'operator');
    }

    public function test_mobile_login_includes_operator_registration_details(): void
    {
        $operator = User::factory()->create([
            'username' => 'jayar.operator',
            'role' => UserRole::Operator,
            'address' => null,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $operator->organization_id,
            'name' => 'CAIX',
            'code' => 'PB-001',
        ]);
        $boat->operator?->update([
            'user_id' => $operator->id,
            'first_name' => 'Jay-Ar',
            'last_name' => 'Sagaga',
            'full_name' => 'Jay-Ar Sagaga',
            'age' => 32,
            'gender' => 'Male',
            'contact_number' => '09123456789',
            'address' => 'Barangay Lakandula, Siargao',
            'experience_years' => 5,
            'emergency_contact_name' => 'Maria Sagaga',
            'emergency_contact_number' => '09987654321',
        ]);

        $this->postJson('/api/login', [
            'username' => 'jayar.operator',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.contact_number', '09123456789')
            ->assertJsonPath('user.operator.address', 'Barangay Lakandula, Siargao')
            ->assertJsonPath('user.operator.full_name', 'Jay-Ar Sagaga')
            ->assertJsonPath('user.operator.age', 32)
            ->assertJsonPath('user.operator.gender', 'Male')
            ->assertJsonPath('user.operator.experience_years', 5)
            ->assertJsonPath('user.operator.emergency_contact_name', 'Maria Sagaga')
            ->assertJsonPath('user.operator.boat_name', 'CAIX')
            ->assertJsonPath('user.operator.boat_code', 'PB-001');
    }

    public function test_inactive_operator_cannot_log_in_on_mobile(): void
    {
        User::factory()->create([
            'username' => 'inactive.operator',
            'role' => UserRole::Operator,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'inactive.operator',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_forgot_password_emails_a_reset_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'maria@safesakay.test',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'maria@safesakay.test',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'We sent a reset code to maria@safesakay.test. Open that inbox (and Spam), not the SafeSakay Gmail.');

        Notification::assertSentTo($user, PasswordResetCodeNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'maria@safesakay.test',
        ]);
    }

    public function test_forgot_password_rejects_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', [
            'email' => 'nobody@safesakay.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_mobile_password_can_be_reset_with_email_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'maria@safesakay.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $code = null;
        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            function (PasswordResetCodeNotification $notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            }
        );

        $this->postJson('/api/verify-reset-code', [
            'email' => $user->email,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
        ])->assertOk();

        $this->assertTrue(Hash::check('SafeSakay@1', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_invalid_reset_code_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'maria@safesakay.test',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $this->postJson('/api/verify-reset-code', [
            'email' => $user->email,
            'code' => '000000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_mobile_service_areas_include_every_active_organization(): void
    {
        $lakandula = \App\Models\Organization::factory()->create([
            'name' => 'BARANGAY LAKANDULA',
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $sanisani = \App\Models\Organization::factory()->create([
            'name' => 'BARANGAY SANISANI',
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $delCarmen = \App\Models\Organization::factory()->create([
            'name' => 'Del Carmen',
            'is_active' => true,
            'approved_at' => now(),
        ]);
        \App\Models\Organization::factory()->create([
            'name' => 'Pending Barangay',
            'is_active' => false,
            'approved_at' => null,
        ]);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $lakandula->id)
            ->assertJsonFragment(['id' => $sanisani->id, 'name' => 'Barangay Sanisani'])
            ->assertJsonFragment(['id' => $delCarmen->id, 'name' => 'Del Carmen'])
            ->assertJsonFragment(['id' => $lakandula->id, 'name' => 'Barangay Lakandula']);
    }

    public function test_mobile_register_rejects_a_weak_password(): void
    {
        \App\Models\Organization::factory()->create([
            'name' => 'Barangay Lakandula',
            'address' => 'Lakandula, Del Carmen, Siargao',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Maria Santos',
            'email' => 'maria@safesakay.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}
