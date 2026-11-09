<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AdminNotificationFeed;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperAdminPlatformToolsTest extends TestCase
{
    use RefreshDatabase;

    private function settingsPayload(array $overrides = []): array
    {
        return [
            'platform_name' => 'SafeSakay',
            'platform_description' => 'Pump boat monitoring',
            'notify_organization_registration' => true,
            'notify_approval_requests' => true,
            'notify_system_activity' => true,
            'session_timeout' => 120,
            'max_login_attempts' => 5,
            ...$overrides,
        ];
    }

    public function test_system_settings_page_shows_saved_values(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('platform.system-settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/SystemSettings')
                ->where('settings.platform_name', 'SafeSakay')
                ->where('settings.session_timeout', 120)
                ->where('options.login_attempts', [3, 5, 10]));
    }

    public function test_saving_settings_persists_and_writes_an_audit_entry(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload([
                'platform_name' => 'SafeSakay Siargao',
                'session_timeout' => 30,
                'max_login_attempts' => 3,
            ]))
            ->assertRedirect(route('platform.system-settings'))
            ->assertSessionHasNoErrors();

        $settings = SystemSetting::query()->findOrFail(1);
        $this->assertSame('SafeSakay Siargao', $settings->platform_name);
        $this->assertSame(30, $settings->session_timeout);
        $this->assertSame(3, PlatformSettings::maxLoginAttempts());
        $this->assertSame('SafeSakay Siargao', PlatformSettings::branding()['name']);

        $event = PlatformEvent::query()->where('action', 'settings_updated')->firstOrFail();
        $this->assertSame($superAdmin->id, $event->actor_id);
        $this->assertStringContainsString('Platform name', (string) $event->note);
        $this->assertStringContainsString('Session timeout', (string) $event->note);
    }

    public function test_saving_without_changes_does_not_add_an_audit_entry(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        PlatformSettings::model();

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload([
                'platform_description' => PlatformSettings::DEFAULTS['platform_description'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PlatformEvent::query()->where('action', 'settings_updated')->count());
    }

    public function test_invalid_security_values_are_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload([
                'session_timeout' => 7,
                'max_login_attempts' => 99,
            ]))
            ->assertSessionHasErrors(['session_timeout', 'max_login_attempts']);
    }

    public function test_barangay_admin_cannot_change_system_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload(['platform_name' => 'Hacked']))
            ->assertRedirect();

        $this->assertNotSame('Hacked', SystemSetting::query()->find(1)?->platform_name);
        $this->assertSame(0, PlatformEvent::query()->where('action', 'settings_updated')->count());
    }

    public function test_session_timeout_setting_is_applied_to_web_requests(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload(['session_timeout' => 15]));

        $this->actingAs($superAdmin)->get(route('platform.dashboard'))->assertOk();

        $this->assertSame(15, config('session.lifetime'));
    }

    public function test_login_attempt_limit_follows_the_setting(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['email' => 'root@safesakay.test']);

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload(['max_login_attempts' => 3]));

        auth()->logout();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('superadmin.login.store'), [
                'email' => 'root@safesakay.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('superadmin.login.store'), [
            'email' => 'root@safesakay.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_registration_alerts_can_be_turned_off(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Organization::factory()->create(['approved_at' => null, 'name' => 'Barangay Waiting']);

        $hasPending = fn () => collect(app(AdminNotificationFeed::class)->forUser($superAdmin, 50)['items'])
            ->contains(fn (array $item) => str_starts_with((string) $item['id'], 'org-pending-'));

        $this->assertTrue($hasPending());

        $this->actingAs($superAdmin)
            ->put(route('platform.system-settings.update'), $this->settingsPayload([
                'notify_organization_registration' => false,
            ]));

        $this->assertFalse($hasPending());
    }

    public function test_rejecting_a_registration_requires_a_reason(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $organization = Organization::factory()->create(['approved_at' => null]);

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.reject', $organization), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNotNull($organization->fresh());
    }

    public function test_pending_page_includes_contact_and_registration_date(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Organization::factory()->create([
            'approved_at' => null,
            'contact_number' => '09170000001',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('platform.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('organizations.0.contact_number', '09170000001')
                ->whereType('organizations.0.registered_at_label', 'string'));
    }

    public function test_audit_logs_filter_across_all_pages(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (range(1, 25) as $index) {
            PlatformEvent::query()->create([
                'actor_id' => $superAdmin->id,
                'organization_name' => 'Barangay '.$index,
                'action' => 'approved',
            ]);
        }

        PlatformEvent::query()->create([
            'actor_id' => $superAdmin->id,
            'organization_name' => 'Barangay Needle',
            'action' => 'deactivated',
            'note' => 'Unused account',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('platform.audit-logs', ['action' => 'deactivated']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.organization_name', 'Barangay Needle')
                ->where('stats.total', 26));

        $this->actingAs($superAdmin)
            ->get(route('platform.audit-logs', ['search' => 'Needle']))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1));

        $this->actingAs($superAdmin)
            ->get(route('platform.audit-logs', ['date' => now()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 26));
    }

    public function test_reports_include_finance_per_barangay_and_export(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $organization = Organization::factory()->create(['name' => 'Barangay Finance']);

        $this->actingAs($superAdmin)
            ->get(route('platform.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('finance')
                ->where('finance', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['id'] === $organization->id && (float) $row['collected'] === 0.0,
                )));

        $response = $this->actingAs($superAdmin)->get(route('platform.reports.export', 'finance'));
        $response->assertOk();
        $this->assertStringContainsString('Barangay Finance', $response->streamedContent());
    }

    public function test_dashboard_reports_operator_passenger_and_commission_totals(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $organization = Organization::factory()->create();
        User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::Operator]);
        User::factory()->count(2)->create(['organization_id' => $organization->id, 'role' => UserRole::Passenger]);

        $this->actingAs($superAdmin)
            ->get(route('platform.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.operators', 1)
                ->where('stats.passengers', 2)
                ->has('stats.commission'));
    }
}
