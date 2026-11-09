<?php

namespace App\Http\Controllers;

use App\Models\AdminNotificationState;
use App\Services\AdminNotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminNotificationController extends Controller
{
    public function index(AdminNotificationFeed $feed): Response
    {
        $payload = $feed->forUser(auth()->user(), 80);

        return Inertia::render('notifications/Index', [
            'notifications' => $payload['items'],
            'counts' => $payload['counts'],
            'unreadCount' => $payload['unread_count'],
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->touchState($request, $notification, read: true);

        return back();
    }

    public function markAllRead(Request $request, AdminNotificationFeed $feed): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            foreach ($feed->sourceKeysForUser($user) as $key) {
                $this->touchState($request, $key, read: true);
            }
        }

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->touchState($request, $notification, deleted: true);

        return back();
    }

    private function touchState(Request $request, string $key, bool $read = false, bool $deleted = false): void
    {
        $user = $request->user();

        if (! $user || $key === '') {
            return;
        }

        $state = AdminNotificationState::query()->firstOrNew([
            'user_id' => $user->id,
            'source_key' => $key,
        ]);

        if ($read) {
            $state->read_at ??= now();
        }

        if ($deleted) {
            $state->deleted_at ??= now();
            $state->read_at ??= now();
        }

        $state->save();
    }
}
