<?php

namespace Tests\Feature\Settings;

use App\Enums\BoatBookingStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use App\Services\AdminNotificationFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_sections_render(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)->get('/settings/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Profile')->where('organization_settings.status_label', 'Active'));

        $this->actingAs($admin)->get('/settings/commission')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Commission')->where('can_manage', true));

        $this->actingAs($admin)->get('/settings/rental-policies')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/RentalPolicies')
                ->where('rules.passenger_grace_minutes', 15)
                ->has('policy.sections', 7)
                ->where('policy.sections.0.title', 'Operator Eligibility')
                ->has('policy.version'));

        $this->actingAs($admin)->get('/settings/notifications')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Notifications')->has('items', 9));
    }

    public function test_rental_policy_grace_period_saves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->post('/settings/payments/no-show-rules', ['passenger_grace_minutes' => 20, 'operator_grace_minutes' => 25])
            ->assertRedirect('/settings/rental-policies');

        $organization = $admin->organization->fresh();
        $this->assertSame(20, $organization->passengerNoShowGraceMinutes());
        $this->assertSame(25, $organization->operatorNoShowGraceMinutes());
    }

    public function test_notification_preferences_save_and_filter_the_bell(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $passenger = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Passenger]);
        $boat = Boat::factory()->create(['organization_id' => $admin->organization_id]);
        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-77777',
            'customer_name' => 'Maria',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
        ]);

        $titles = fn () => collect(app(AdminNotificationFeed::class)->forUser($admin->fresh())['items'])->pluck('title');
        $this->assertContains('New rental request', $titles());

        $this->actingAs($admin)
            ->post('/settings/notifications', ['preferences' => [
                'new_rental_requests' => false,
                'passenger_no_show' => false,
            ]])
            ->assertRedirect('/settings/notifications');

        $saved = $admin->fresh()->notification_preferences;
        $this->assertFalse($saved['new_rental_requests']);
        $this->assertTrue($saved['passenger_no_show'], 'Safety alerts cannot be turned off.');
        $this->assertNotContains('New rental request', $titles());
    }

    public function test_super_admin_cannot_open_barangay_settings(): void
    {
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin, 'organization_id' => null]);

        $this->actingAs($superAdmin)->get('/settings/commission')->assertRedirect();
        $this->actingAs($superAdmin)->get('/settings/notifications')->assertRedirect();
    }
}
