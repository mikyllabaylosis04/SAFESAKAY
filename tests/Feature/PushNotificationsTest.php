<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use App\Services\PushNotificationForwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abc123XYZ]';

    public function test_signed_in_user_can_register_a_device(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/push-tokens', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertOk();

        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $user->id,
            'token' => self::TOKEN,
            'platform' => 'android',
        ]);
    }

    public function test_guests_and_invalid_tokens_are_rejected(): void
    {
        $this->postJson('/api/push-tokens', ['token' => self::TOKEN])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/push-tokens', ['token' => 'not-an-expo-token'])
            ->assertUnprocessable();
    }

    public function test_device_moves_to_the_user_who_signed_in_last(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        PushToken::query()->create(['user_id' => $first->id, 'token' => self::TOKEN]);

        Sanctum::actingAs($second);
        $this->postJson('/api/push-tokens', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(1, PushToken::query()->count());
        $this->assertSame($second->id, PushToken::query()->value('user_id'));
    }

    public function test_user_can_only_remove_their_own_device(): void
    {
        $owner = User::factory()->create();
        PushToken::query()->create(['user_id' => $owner->id, 'token' => self::TOKEN]);

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson('/api/push-tokens', ['token' => self::TOKEN])->assertOk()->assertJson(['deleted' => 0]);
        $this->assertDatabaseCount('push_tokens', 1);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/push-tokens', ['token' => self::TOKEN])->assertOk()->assertJson(['deleted' => 1]);
        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_in_app_notification_is_also_sent_as_push(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);

        $user->notify(new PushTestNotification(bookingId: 42, event: 'approved'));

        $this->assertDatabaseCount('notifications', 1);
        Http::assertSent(function (Request $request) {
            $message = $request->data()[0] ?? [];

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && $message['to'] === self::TOKEN
                && $message['title'] === 'Rental confirmed'
                && $message['channelId'] === 'default'
                && $message['data']['url'] === '/booking-details?id=42'
                && $message['data']['booking_id'] === 42;
        });
        $this->assertNotNull(PushToken::query()->value('last_used_at'));
    }

    public function test_completed_trip_push_opens_feedback(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);

        $user->notify(new PushTestNotification(bookingId: 7, event: 'completed'));

        Http::assertSent(fn (Request $request) => $request->data()[0]['data']['url'] === '/feedback?id=7');
    }

    public function test_unregistered_device_is_removed(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [[
            'status' => 'error',
            'message' => 'Device not registered',
            'details' => ['error' => 'DeviceNotRegistered'],
        ]]])]);

        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);

        $user->notify(new PushTestNotification(bookingId: 1, event: 'approved'));

        $this->assertDatabaseCount('push_tokens', 0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_notification_still_saves_when_push_storage_is_unavailable(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Schema::drop('push_tokens');

        $user->notify(new PushTestNotification(bookingId: 3, event: 'approved'));

        $this->assertDatabaseCount('notifications', 1);
        Http::assertNothingSent();
    }

    public function test_no_push_without_device_or_when_paused_or_on_expo_failure(): void
    {
        Http::fake(['exp.host/*' => Http::response([], 500)]);

        $withoutDevice = User::factory()->create();
        $withoutDevice->notify(new PushTestNotification(bookingId: 1, event: 'approved'));
        Http::assertNothingSent();

        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);

        PushNotificationForwarder::withoutPush(
            fn () => $user->notify(new PushTestNotification(bookingId: 1, event: 'approved'))
        );
        Http::assertNothingSent();

        $user->notify(new PushTestNotification(bookingId: 2, event: 'approved'));
        Http::assertSentCount(1);

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('push_tokens', 1);
    }
}

class PushTestNotification extends Notification
{
    public function __construct(private int $bookingId, private string $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'booking',
            'event' => $this->event,
            'booking_id' => $this->bookingId,
            'title' => 'Rental confirmed',
            'body' => 'Your rental is confirmed.',
        ];
    }
}
