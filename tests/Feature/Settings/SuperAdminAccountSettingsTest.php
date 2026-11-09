<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperAdminAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_account_information(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Platform Owner']);

        $this->actingAs($superAdmin)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Profile')
                ->where('account.name', 'Platform Owner')
                ->where('organization_settings', null));
    }

    public function test_super_admin_can_update_profile(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('profile.update'), [
                'name' => 'New Super Admin Name',
                'email' => $superAdmin->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('New Super Admin Name', $superAdmin->refresh()->name);
    }

    public function test_super_admin_can_change_password(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('SafeSakay@1', $superAdmin->refresh()->password));
    }
}
