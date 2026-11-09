<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_url_shows_its_own_login_page(): void
    {
        $this->get('/superadmin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/Login'));

        $this->get('/super-admin')->assertRedirect('/superadmin');
    }

    public function test_platform_url_sends_guests_to_super_admin_login(): void
    {
        $this->get('/platform')->assertRedirect('/superadmin');
    }

    public function test_super_admin_login_goes_to_the_platform_dashboard(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'superadmin@safesakay.com',
            'password' => 'SafeSakay@Super1',
        ]);

        $this->post(route('superadmin.login.store'), [
            'email' => $user->email,
            'password' => 'SafeSakay@Super1',
        ])
            ->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_barangay_admin_cannot_use_super_admin_login(): void
    {
        $admin = User::factory()->create();

        $this->from('/superadmin')
            ->post(route('superadmin.login.store'), [
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_cannot_use_barangay_admin_login(): void
    {
        $user = User::factory()->superAdmin()->create([
            'password' => 'SafeSakay@Super1',
        ]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'SafeSakay@Super1',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_can_open_platform_pages(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'barangay-test',
        ]);
        User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/Dashboard')
                ->where('stats.organizations', 1)
                ->where('auth.is_super_admin', true));

        $this->actingAs($superAdmin)
            ->get(route('platform.organizations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/Organizations')
                ->has('organizations', 1));

        $this->actingAs($superAdmin)->get(route('platform.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/Pending'));

        $this->actingAs($superAdmin)->get(route('platform.admins'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/Admins'));

        $this->actingAs($superAdmin)->get(route('platform.organization-requests'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/OrganizationRequests'));

        $this->actingAs($superAdmin)->get(route('platform.organizations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/Onboard'));
    }

    public function test_super_admin_cannot_open_barangay_modules(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertRedirect(route('platform.dashboard'));

        $this->actingAs($superAdmin)
            ->get(route('boats.index'))
            ->assertRedirect(route('platform.dashboard'));

        $this->actingAs($superAdmin)
            ->get(route('bookings.requests'))
            ->assertRedirect(route('platform.dashboard'));

        $this->actingAs($superAdmin)
            ->get(route('settings.payments.edit'))
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_barangay_admin_cannot_open_platform(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('platform.dashboard'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_barangay_admin_cannot_create_a_super_admin(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Platform Owner',
                'email' => 'owner@safesakay.test',
                'role' => UserRole::SuperAdmin->value,
                'is_active' => '1',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'owner@safesakay.test',
        ]);
    }

    public function test_new_organization_stays_pending_until_super_admin_approves(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Port Admin',
            'email' => 'pending-admin@test.com',
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
            'organization_name' => 'Barangay Pending Port',
            'organization_address' => 'Pending Port, Siargao',
            'contact_number' => '09123456789',
            'terms' => '1',
        ]);

        $organization = Organization::query()->where('name', 'Barangay Pending Port')->first();
        $admin = User::query()->where('email', 'pending-admin@test.com')->first();
        $this->assertNotNull($organization);
        $this->assertNotNull($admin);
        $this->assertTrue($organization->isPending());

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'SafeSakay@1',
        ])->assertSessionHasErrors('email');

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.approve', $organization))
            ->assertRedirect();

        $this->assertTrue($organization->fresh()->allowsAccess());

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'SafeSakay@1',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_super_admin_can_view_and_deactivate_an_organization(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Test Port',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('platform.organizations.show', $organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/OrganizationShow')
                ->where('organization.name', 'Barangay Test Port')
                ->where('organization.is_active', true));

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.deactivate', $organization), [
                'reason' => 'Unpaid subscription',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => false,
            'suspended_reason' => 'Unpaid subscription',
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.restore', $organization))
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => true,
            'suspended_reason' => null,
        ]);

        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'deactivated',
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'restored',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('platform.reports.access-history'))
            ->assertRedirect(route('platform.reports'));

        $this->actingAs($superAdmin)
            ->get(route('platform.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/Reports')
                ->has('access_history')
                ->has('activity')
                ->has('organizations')
                ->where('access_history.0.action', 'restored')
                ->where('access_history.0.organization_id', $organization->id)
                ->where('access_history.1.action', 'deactivated'));

        $this->actingAs($superAdmin)
            ->get(route('platform.organizations.show', $organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('organization.access_history')
                ->where('organization.access_history.0.action', 'restored')
                ->where('organization.last_change_label', 'Restored'));
    }

    public function test_super_admin_can_reject_a_pending_organization(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Port Admin',
            'email' => 'reject-admin@test.com',
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
            'organization_name' => 'Barangay Reject Port',
            'organization_address' => 'Reject Port, Siargao',
            'contact_number' => '09123456780',
            'terms' => '1',
        ]);

        $organization = Organization::query()->where('name', 'Barangay Reject Port')->first();
        $this->assertNotNull($organization);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.reject', $organization), [
                'reason' => 'Incomplete barangay details',
            ])
            ->assertRedirect(route('platform.pending'));

        $this->assertDatabaseMissing('organizations', [
            'name' => 'Barangay Reject Port',
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_name' => 'Barangay Reject Port',
            'action' => 'rejected',
        ]);
    }

    public function test_super_admin_can_onboard_an_approved_organization(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('platform.organizations.store'), [
                'organization_name' => 'Barangay Onboard Port',
                'organization_address' => 'Onboard Port, Siargao',
                'contact_number' => '09111111111',
                'name' => 'Onboard Admin',
                'email' => 'onboard-admin@test.com',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertRedirect();

        $organization = Organization::query()->where('name', 'Barangay Onboard Port')->first();
        $this->assertNotNull($organization);
        $this->assertTrue($organization->allowsAccess());
        $this->assertSame(0, $organization->routes()->count());

        $this->post(route('login.store'), [
            'email' => 'onboard-admin@test.com',
            'password' => 'SafeSakay@1',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_suspended_admin_can_request_restore_from_login(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Appeal Port',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
            'email' => 'appeal-admin@test.com',
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.deactivate', $organization), [
                'reason' => 'Unpaid subscription',
            ])
            ->assertRedirect();

        auth()->logout();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post(route('restore-request.store'), [
            'email' => $admin->email,
            'reason' => 'Organization operations have resumed',
            'details' => 'Boats are ready and the barangay wants access restored.',
        ])->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'restore_request_reason' => 'Organization operations have resumed',
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'restore_requested',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('platform.organization-requests'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/OrganizationRequests')
                ->has('restore_requests', 1)
                ->where('restore_requests.0.id', $organization->id));

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.restore', $organization))
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => true,
            'restore_request_reason' => null,
        ]);
    }

    public function test_super_admin_can_deactivate_an_unused_organization(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Seasonal Port',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.deactivate', $organization), [
                'reason' => 'Off-season, unused for now',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => false,
            'inactive_kind' => 'deactivated',
            'suspended_reason' => 'Off-season, unused for now',
        ]);

        auth()->logout();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post(route('restore-request.store'), [
            'email' => $admin->email,
            'reason' => 'Ready to use SafeSakay again',
            'details' => 'The off-season is over and trips can resume.',
        ])->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'restore_request_reason' => 'Ready to use SafeSakay again',
        ]);
    }

    public function test_unknown_restore_request_does_not_create_a_record(): void
    {
        $this->post(route('restore-request.store'), [
            'email' => 'missing-admin@test.com',
            'reason' => 'Ready to use SafeSakay again',
            'details' => 'This account should be reviewed by Super Admin.',
        ])->assertRedirect();

        $this->assertDatabaseMissing('platform_events', [
            'action' => 'restore_requested',
        ]);
    }

    public function test_super_admin_can_dismiss_a_restore_request(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.deactivate', $organization), [
                'reason' => 'Unpaid subscription',
            ])
            ->assertRedirect();

        auth()->logout();

        $this->post(route('restore-request.store'), [
            'email' => $admin->email,
            'reason' => 'Financial or subscription concerns resolved',
            'details' => 'The subscription concern is settled and access can return.',
        ])->assertRedirect();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.restore-request.dismiss', $organization))
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => false,
            'restore_request_reason' => null,
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'restore_request_dismissed',
        ]);
    }

    public function test_super_admin_can_deactivate_an_extra_barangay_admin(): void
    {
        $organization = Organization::factory()->create();
        $keep = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
            'email' => 'keep.admin@safesakay.test',
        ]);
        $unused = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
            'email' => 'unused.admin@safesakay.test',
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.admins.status', $unused), [
                'is_active' => false,
                'reason' => 'Unused extra admin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $unused->id,
            'is_active' => false,
            'deactivated_reason' => 'Unused extra admin',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $keep->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'admin_deactivated',
        ]);

        auth()->logout();

        $this->post(route('login.store'), [
            'email' => $unused->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post(route('login.store'), [
            'email' => $keep->email,
            'password' => 'password',
        ])->assertRedirect();
    }

    public function test_super_admin_cannot_deactivate_the_last_barangay_admin(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->from(route('platform.admins'))
            ->patch(route('platform.admins.status', $admin), [
                'is_active' => false,
                'reason' => 'Trying to close the last admin',
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_restore_a_deactivated_barangay_admin(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $unused = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
            'is_active' => false,
            'deactivated_reason' => 'Leave',
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.admins.status', $unused), [
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $unused->id,
            'is_active' => true,
            'deactivated_reason' => null,
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization->id,
            'action' => 'admin_restored',
        ]);
    }
}
