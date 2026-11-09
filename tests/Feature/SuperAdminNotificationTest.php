<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Announcement;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeatherSuspension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_pending_orgs_and_not_barangay_rentals(): void
    {
        $barangayAdmin = User::factory()->create();
        $announcement = Announcement::query()->create([
            'organization_id' => $barangayAdmin->organization_id,
            'user_id' => $barangayAdmin->id,
            'title' => 'Barangay only notice',
            'body' => 'Operators stay at port.',
            'category' => 'general',
            'audience' => ['operator'],
            'recipient_count' => 1,
            'published_at' => now(),
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $barangayAdmin->organization_id,
        ]);
        $booking = BoatBooking::query()->create([
            'organization_id' => $barangayAdmin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-01099',
            'customer_name' => 'Miky',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
        ]);

        $pending = Organization::factory()->create([
            'name' => 'Barangay Pending Port',
            'is_active' => false,
            'approved_at' => null,
        ]);
        User::factory()->create([
            'organization_id' => $pending->id,
            'role' => UserRole::Administrator,
            'name' => 'Pending Admin',
            'email' => 'pending-admin@test.com',
        ]);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('headerNotifications.items', function ($items) use ($pending, $announcement, $booking) {
                    $ids = collect($items)->pluck('id');
                    $hrefs = collect($items)->pluck('href');

                    return $ids->contains('org-pending-'.$pending->id)
                        && $ids->doesntContain('announcement-'.$announcement->id)
                        && $ids->doesntContain('booking-'.$booking->id)
                        && $hrefs->every(fn ($href) => str_starts_with((string) $href, '/platform/'));
                }));
    }

    public function test_super_admin_notifications_show_access_events_without_weather_holds(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Lakandula',
            'is_active' => false,
            'approved_at' => null,
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::Administrator,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->patch(route('platform.organizations.approve', $organization))
            ->assertRedirect();

        $hold = WeatherSuspension::query()->create([
            'organization_id' => $organization->id,
            'declared_by' => $admin->id,
            'code' => 'WS-2026-00001',
            'reason' => WeatherHoldReason::BadWeather,
            'affected_date' => now()->toDateString(),
            'affected_routes' => ['All routes'],
            'duration_type' => 'whole_day',
            'announcement_message' => 'No trips due to bad weather.',
            'status' => WeatherSuspensionStatus::Active,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/Index')
                ->where('notifications', function ($items) use ($organization, $hold) {
                    $ids = collect($items)->pluck('id');

                    return ! $ids->contains('weather-hold-'.$hold->id)
                        && $ids->contains(fn ($id) => str_starts_with((string) $id, 'platform-event-'))
                        && collect($items)->contains(
                            fn ($item) => $item['title'] === 'Barangay Lakandula was approved'
                                && $item['href'] === '/platform/organizations/'.$organization->id,
                        );
                }));
    }

    public function test_super_admin_can_mark_platform_notification_read(): void
    {
        $pending = Organization::factory()->create([
            'name' => 'Barangay Notice Port',
            'is_active' => false,
            'approved_at' => null,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();
        $key = 'org-pending-'.$pending->id;

        $this->actingAs($superAdmin)
            ->post(route('notifications.read', $key))
            ->assertRedirect();

        $this->actingAs($superAdmin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications', function ($items) use ($key) {
                    $item = collect($items)->firstWhere('id', $key);

                    return $item && $item['unread'] === false;
                }));
    }
}
