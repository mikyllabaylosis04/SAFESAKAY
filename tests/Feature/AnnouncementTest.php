<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_announcements_page(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $this->actingAs($admin)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('announcements/Index')
                ->where('stats.total', 0)
                ->where('announcements', []));
    }

    public function test_admin_can_send_an_announcement_to_operators_users_and_passengers(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);

        $legacyStaff = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Staff,
            'username' => 'legacy.staff',
        ]);

        $staffUser = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::User,
        ]);

        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'username' => 'maria.passenger',
        ]);

        $inactiveOperator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'inactive.operator',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->from(route('announcements.index'))
            ->post(route('announcements.store'), [
                'title' => 'No trips today due to bad weather',
                'body' => 'All regular trips are suspended today because of bad weather.',
                'category' => 'weather',
                'audience' => ['operator', 'user', 'passenger'],
            ])
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'organization_id' => $admin->organization_id,
            'user_id' => $admin->id,
            'title' => 'No trips today due to bad weather',
            'category' => 'weather',
            'recipient_count' => 4,
        ]);

        $this->assertDatabaseHas('weather_suspensions', [
            'organization_id' => $admin->organization_id,
            'status' => 'active',
        ]);

        foreach ([$operator, $legacyStaff, $staffUser, $passenger] as $recipient) {
            $this->assertTrue(
                $recipient->fresh()->notifications->contains(
                    fn ($notification) => $notification->type === AnnouncementNotification::class
                        && ($notification->data['kind'] ?? null) === 'announcement'
                        && ($notification->data['title'] ?? null) === 'No trips today due to bad weather',
                ),
            );
        }

        $this->assertCount(0, $inactiveOperator->fresh()->notifications);
        $this->assertCount(0, $admin->fresh()->notifications);
    }

    public function test_announcements_stay_inside_the_organization(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $otherAdmin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $otherOperator = User::factory()->create([
            'organization_id' => $otherAdmin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'other.operator',
        ]);

        $this->actingAs($admin)
            ->post(route('announcements.store'), [
                'title' => 'No trips today due to bad weather',
                'body' => 'Stay at home until the next advisory.',
                'category' => 'weather',
                'audience' => ['operator'],
            ])
            ->assertRedirect(route('announcements.index'));

        $this->assertCount(0, $otherOperator->fresh()->notifications);

        $this->actingAs($otherAdmin)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 0)
                ->where('announcements', []));

        $this->actingAs($admin)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('stats.today', 1)
                ->where('announcements.0.title', 'No trips today due to bad weather')
                ->where('announcements.0.sent_today', true));
    }

    public function test_operators_and_passengers_see_announcements_in_the_mobile_inbox(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Administrator,
        ]);

        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);

        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'username' => 'maria.passenger',
        ]);

        $this->actingAs($admin)->post(route('announcements.store'), [
            'title' => 'No trips today due to bad weather',
            'body' => 'All regular trips are suspended today because of bad weather.',
            'category' => 'weather',
            'audience' => ['operator', 'passenger'],
        ]);

        $announcement = Announcement::query()->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'announcement')
            ->assertJsonPath('data.0.event', 'weather')
            ->assertJsonPath('data.0.title', 'No trips today due to bad weather')
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'announcement')
            ->assertJsonPath('data.0.body', 'All regular trips are suspended today because of bad weather.')
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.0.type', 'announcement')
                ->where('notifications.0.title', 'No trips today due to bad weather')
                ->where('notifications.0.href', '/announcements')
                ->where('notifications.0.id', 'announcement-'.$announcement->id));
    }

    public function test_non_weather_announcements_post_without_weather_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        foreach (['delay', 'general', 'emergency'] as $category) {
            $this->actingAs($admin)
                ->post(route('announcements.store'), [
                    'title' => 'Update for '.$category,
                    'body' => 'Please wait for further updates.',
                    'category' => $category,
                    'audience' => ['operator', 'passenger'],
                    'reason' => null,
                    'affected_date' => null,
                    'affected_routes' => [],
                    'duration_type' => null,
                    'starts_at' => null,
                    'ends_at' => null,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('announcements.index'));

            $this->assertDatabaseHas('announcements', ['category' => $category, 'title' => 'Update for '.$category]);
        }

        $this->assertSame(0, \App\Models\WeatherSuspension::query()->count());
    }

    public function test_announcement_requires_title_message_and_audience(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('announcements.index'))
            ->post(route('announcements.store'), [
                'title' => '',
                'body' => '',
                'category' => 'weather',
                'audience' => [],
            ])
            ->assertRedirect(route('announcements.index'))
            ->assertSessionHasErrors(['title', 'body', 'audience']);
    }
}
