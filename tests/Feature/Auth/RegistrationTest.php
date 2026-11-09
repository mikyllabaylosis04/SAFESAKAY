<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_organizations_can_register_without_being_signed_in()
    {
        $response = $this->post(route('register.store'), $this->registrationPayload());

        $this->assertGuest();
        $response->assertRedirect(route('register'));
        $response->assertSessionHas('accountCreated', true);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Barangay Lakandula',
        ]);

        $user = User::query()->where('email', 'admin@lakandula.test')->first();
        $organization = Organization::query()->where('name', 'Barangay Lakandula')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($organization);
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertSame(UserRole::Administrator, $user->role);
        $this->assertNotEmpty($organization->tenant_id);
        $this->assertMatchesRegularExpression('/^SS-[A-Z0-9]{8}$/', $organization->tenant_id);
        $this->assertSame(0, $organization->routes()->count());
    }

    public function test_each_registered_organization_gets_a_unique_tenant_id(): void
    {
        $this->post(route('register.store'), $this->registrationPayload());
        $this->post(route('register.store'), [
            ...$this->registrationPayload(),
            'organization_name' => 'Barangay Dapa',
            'organization_address' => 'Dapa, Siargao',
            'email' => 'admin@dapa.test',
        ]);

        $tenantIds = Organization::query()
            ->whereIn('name', ['Barangay Lakandula', 'Barangay Dapa'])
            ->pluck('tenant_id');

        $this->assertCount(2, $tenantIds);
        $this->assertCount(2, $tenantIds->unique());
    }

    public function test_successful_registration_shows_the_account_created_confirmation()
    {
        $this->post(route('register.store'), $this->registrationPayload())
            ->assertRedirect(route('register'));

        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/Register')
                ->where('accountCreated', true));
    }

    public function test_registered_users_must_log_in_before_reaching_the_dashboard()
    {
        $this->post(route('register.store'), $this->registrationPayload());

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => 'admin@lakandula.test',
            'password' => 'SafeSakay@1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $organization = Organization::query()->where('name', 'Barangay Lakandula')->first();
        $this->assertTrue($organization?->isPending());

        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.approve', $organization))
            ->assertRedirect();

        $this->post(route('login.store'), [
            'email' => 'admin@lakandula.test',
            'password' => 'SafeSakay@1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_a_second_organization_cannot_register_the_same_barangay_address(): void
    {
        $this->post(route('register.store'), $this->registrationPayload())
            ->assertRedirect(route('register'));

        $this->from(route('register'))
            ->post(route('register.store'), [
                ...$this->registrationPayload(),
                'organization_name' => 'LAKANDULA',
                'organization_address' => 'Barangay Lakandula, Siargao',
                'email' => 'other@lakandula.test',
            ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['organization_address', 'organization_name']);

        $this->assertSame(
            1,
            Organization::query()->where('slug', '!=', 'safesakay')->count(),
        );
        $this->assertDatabaseMissing('users', [
            'email' => 'other@lakandula.test',
        ]);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), [
                ...$this->registrationPayload(),
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'admin@lakandula.test',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(): array
    {
        return [
            'organization_name' => 'Barangay Lakandula',
            'organization_address' => 'Lakandula, Siargao',
            'contact_number' => '09171234567',
            'name' => 'Test Admin',
            'email' => 'admin@lakandula.test',
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
            'terms' => '1',
        ];
    }
}
