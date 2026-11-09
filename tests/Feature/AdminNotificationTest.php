<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Models\AdminNotificationState;
use App\Models\Announcement;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_notifications_use_live_announcements_and_rental_requests(): void
    {
        $admin = User::factory()->create();
        $announcement = Announcement::query()->create([
            'organization_id' => $admin->organization_id,
            'user_id' => $admin->id,
            'title' => 'Safety reminder for today\'s trips',
            'body' => 'Please wear life jackets at all times while on board.',
            'category' => 'general',
            'audience' => ['operator', 'user', 'passenger'],
            'recipient_count' => 7,
            'published_at' => now(),
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);
        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-01012',
            'customer_name' => 'Miky',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('headerNotifications.items')
                ->where('headerNotifications.unread_count', fn ($count) => $count >= 2)
                ->where('headerNotifications.items', function ($items) use ($announcement, $booking) {
                    $ids = collect($items)->pluck('id');

                    return $ids->contains('announcement-'.$announcement->id)
                        && $ids->contains('booking-'.$booking->id);
                }));
    }

    public function test_admin_can_mark_a_notification_as_read_and_delete_it(): void
    {
        $admin = User::factory()->create();
        $announcement = Announcement::query()->create([
            'organization_id' => $admin->organization_id,
            'user_id' => $admin->id,
            'title' => 'Trips are delayed due to rough seas',
            'body' => 'Departures are delayed because of rough sea conditions.',
            'category' => 'delay',
            'audience' => ['operator'],
            'recipient_count' => 2,
            'published_at' => now(),
        ]);
        $key = 'announcement-'.$announcement->id;

        $this->actingAs($admin)
            ->post(route('notifications.read', $key))
            ->assertRedirect();

        $this->assertDatabaseHas('admin_notification_states', [
            'user_id' => $admin->id,
            'source_key' => $key,
        ]);
        $this->assertNotNull(
            AdminNotificationState::query()->where('source_key', $key)->value('read_at'),
        );

        $this->actingAs($admin)
            ->delete(route('notifications.destroy', $key))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications', fn ($items) => collect($items)->every(
                    fn ($item) => $item['id'] !== $key,
                )));
    }

    public function test_mark_all_as_read_clears_the_unread_badge(): void
    {
        $admin = User::factory()->create();
        Announcement::query()->create([
            'organization_id' => $admin->organization_id,
            'user_id' => $admin->id,
            'title' => 'Safety reminder for today\'s trips',
            'body' => 'Please wear life jackets at all times while on board.',
            'category' => 'general',
            'audience' => ['operator'],
            'recipient_count' => 1,
            'published_at' => now(),
        ]);
        Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Maintenance,
            'name' => 'Daryl Mae',
            'code' => 'PB-001',
        ]);

        $this->actingAs($admin)
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unreadCount', 0));
    }
}
