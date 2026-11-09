<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_shows_organization_account_management(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Profile')
                ->where('account.email', $admin->email)
                ->where('organization_settings.name', $admin->organization?->name)
                ->where('organization_settings.can_request_closure', true));
    }

    public function test_barangay_admin_can_request_closure_without_archiving(): void
    {
        $admin = User::factory()->create();
        $organization = $admin->organization;

        $this->actingAs($admin)
            ->post(route('settings.organization.closure'), [
                'reason' => 'No longer operating pump boats',
                'details' => 'The barangay will stop using SafeSakay after this season.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization?->id,
            'is_active' => true,
            'closure_reason' => 'No longer operating pump boats',
            'closure_details' => 'The barangay will stop using SafeSakay after this season.',
        ]);
        $this->assertDatabaseHas('platform_events', [
            'organization_id' => $organization?->id,
            'action' => 'closure_requested',
        ]);
    }

    public function test_other_closure_reason_requires_a_specified_reason(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('profile.edit'))
            ->post(route('settings.organization.closure'), [
                'reason' => 'Other reason',
            ])
            ->assertSessionHasErrors('specify_reason');

        $this->actingAs($admin)
            ->post(route('settings.organization.closure'), [
                'reason' => 'Other reason',
                'specify_reason' => 'Port transferred to another barangay',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $admin->organization_id,
            'is_active' => true,
            'closure_reason' => 'Other reason: Port transferred to another barangay',
        ]);
    }

    public function test_super_admin_can_reject_a_closure_request(): void
    {
        $admin = User::factory()->create();
        $organization = $admin->organization;
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('settings.organization.closure'), [
                'reason' => 'Switching to another management system',
            ])
            ->assertRedirect();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.closure.reject', $organization), [
                'reason' => 'Still has active boats this month',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization?->id,
            'is_active' => true,
            'closure_requested_at' => null,
            'closure_rejection_reason' => 'Still has active boats this month',
        ]);
    }

    public function test_super_admin_approval_archives_the_organization(): void
    {
        $admin = User::factory()->create();
        $organization = $admin->organization;
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('settings.organization.closure'), [
                'reason' => 'Other reason',
                'specify_reason' => 'Port transferred to another barangay',
            ])
            ->assertRedirect();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.closure.approve', $organization))
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization?->id,
            'is_active' => false,
            'inactive_kind' => 'archived',
        ]);
    }

    public function test_archived_admin_can_login_to_the_archived_page(): void
    {
        $organization = Organization::factory()->create([
            'is_active' => false,
            'inactive_kind' => 'archived',
            'approved_at' => now(),
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('organization.archived'));

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('organization.archived'));

        $this->actingAs($admin)
            ->get(route('organization.archived'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('organization/Archived'));
    }

    public function test_archived_admin_can_request_reactivation_and_super_admin_can_restore(): void
    {
        $organization = Organization::factory()->create([
            'is_active' => false,
            'inactive_kind' => 'archived',
            'approved_at' => now(),
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('organization.reactivate'), [
                'reason' => 'Ready to use SafeSakay again',
                'details' => 'Boats are ready for the next season.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'restore_request_reason' => 'Ready to use SafeSakay again',
            'restore_request_details' => 'Boats are ready for the next season.',
            'inactive_kind' => 'archived',
            'is_active' => false,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('platform.organization-requests'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/OrganizationRequests')
                ->has('reactivation_requests', 1));

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.reactivation.approve', $organization))
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => true,
            'inactive_kind' => null,
        ]);
    }

    public function test_other_reactivation_reason_requires_a_specified_reason(): void
    {
        $organization = Organization::factory()->create([
            'is_active' => false,
            'inactive_kind' => 'archived',
            'approved_at' => now(),
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);

        $this->actingAs($admin)
            ->from(route('organization.archived'))
            ->post(route('organization.reactivate'), [
                'reason' => 'Other reason',
            ])
            ->assertSessionHasErrors('specify_reason');

        $this->actingAs($admin)
            ->post(route('organization.reactivate'), [
                'reason' => 'Other reason',
                'specify_reason' => 'New operators were hired this week',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => false,
            'inactive_kind' => 'archived',
            'restore_request_reason' => 'Other reason: New operators were hired this week',
        ]);
    }
}
