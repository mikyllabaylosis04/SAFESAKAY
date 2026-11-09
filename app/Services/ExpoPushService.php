<?php

namespace App\Services;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends push notifications through the Expo Push Service, which delivers them to Android via FCM V1.
 */
class ExpoPushService
{
    /** Expo accepts at most 100 messages per request. */
    private const CHUNK_SIZE = 100;

    public function enabled(): bool
    {
        return (bool) config('services.expo_push.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return int Number of messages Expo accepted.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $tokens = $user->pushTokens()->get(['id', 'token']);

        if ($tokens->isEmpty()) {
            return 0;
        }

        $accepted = 0;

        foreach ($tokens->chunk(self::CHUNK_SIZE) as $chunk) {
            $accepted += $this->sendChunk($user, $chunk->values()->all(), $title, $body, $data);
        }

        return $accepted;
    }

    /**
     * @param  list<PushToken>  $tokens
     * @param  array<string, mixed>  $data
     */
    private function sendChunk(User $user, array $tokens, string $title, string $body, array $data): int
    {
        $messages = array_map(fn (PushToken $token) => [
            'to' => $token->token,
            'title' => Str::limit($title, 120),
            'body' => Str::limit($body, 400),
            'data' => $data,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
        ], $tokens);

        try {
            $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(10);

            $accessToken = trim((string) config('services.expo_push.access_token', ''));
            if ($accessToken !== '') {
                $request = $request->withToken($accessToken);
            }

            $response = $request->post((string) config('services.expo_push.url'), $messages);
        } catch (Throwable $exception) {
            Log::warning('expo_push.request_failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('expo_push.http_error', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'error' => data_get($response->json(), 'errors.0.code'),
            ]);

            return 0;
        }

        $accepted = 0;

        foreach ((array) data_get($response->json(), 'data', []) as $index => $ticket) {
            $pushToken = $tokens[$index] ?? null;

            if (($ticket['status'] ?? null) === 'ok') {
                $accepted++;
                $pushToken?->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            $error = data_get($ticket, 'details.error');

            Log::warning('expo_push.ticket_error', [
                'user_id' => $user->id,
                'push_token_id' => $pushToken?->id,
                'error' => $error,
                'message' => $ticket['message'] ?? null,
            ]);

            if ($error === 'DeviceNotRegistered') {
                $pushToken?->delete();
            }
        }

        return $accepted;
    }
}
