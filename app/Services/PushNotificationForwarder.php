<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mirrors every in-app (database) notification to the user's phones as a push notification,
 * so the existing notification classes do not need their own push channel.
 */
class PushNotificationForwarder
{
    private static bool $paused = false;

    public function __construct(private ExpoPushService $push) {}

    /**
     * Run a callback whose notifications are only stored in-app (e.g. backfilled history).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutPush(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    public function handle(NotificationSent $event): void
    {
        if (self::$paused || $event->channel !== 'database' || ! $this->push->enabled()) {
            return;
        }

        $user = $event->notifiable;

        if (! $user instanceof User || ! $event->response instanceof DatabaseNotification) {
            return;
        }

        $data = (array) $event->response->data;
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));

        if ($title === '' && $body === '') {
            return;
        }

        $payload = array_filter([
            'notification_id' => $event->response->id,
            'kind' => $data['kind'] ?? null,
            'event' => $data['event'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'url' => $this->appPath($data),
        ], fn ($value) => $value !== null);

        $send = function () use ($user, $title, $body, $payload): void {
            // A push failure must never break the booking, payment, or notification that triggered it.
            try {
                $this->push->sendToUser($user, $title !== '' ? $title : 'SafeSakay', $body, $payload);
            } catch (Throwable $exception) {
                Log::warning('expo_push.forward_failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        };

        // Do not make the passenger/operator wait on Expo during a web or API request.
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    /**
     * Same destination the mobile Notifications screen opens for this notice.
     *
     * @param  array<string, mixed>  $data
     */
    private function appPath(array $data): string
    {
        $bookingId = $data['booking_id'] ?? null;

        if (is_numeric($bookingId) && (int) $bookingId > 0) {
            $screen = ($data['event'] ?? null) === 'completed' ? '/feedback' : '/booking-details';

            return $screen.'?id='.(int) $bookingId;
        }

        return '/notification';
    }
}
