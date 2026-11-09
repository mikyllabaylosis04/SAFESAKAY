<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Services\PushNotificationForwarder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            PushNotificationForwarder::withoutPush(fn () => $this->backfill($user));
            $notifications = $user->notifications()
                ->latest()
                ->get()
                ->reject(fn (DatabaseNotification $notification) => ($notification->data['kind'] ?? '') === 'emergency')
                ->values();

            return response()->json([
                'data' => $notifications->map(fn (DatabaseNotification $notification) => $this->transform($notification)),
                'unread_count' => $notifications->where('read_at', null)->count(),
            ]);
        } catch (Throwable) {
            return $this->fromBookings($user);
        }
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $request->user()->notifications()->findOrFail($id);
            $notification->markAsRead();
        } catch (Throwable) {
            // Fallback notices have no stored row.
        }

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    private function backfill(User $user): void
    {
        $bookings = $this->bookingsFor($user)->with('boat')->get();

        $existing = $user->notifications
            ->map(fn (DatabaseNotification $notification) => ($notification->data['booking_id'] ?? '').':'.($notification->data['event'] ?? ''))
            ->all();

        foreach ($bookings as $booking) {
            $key = $booking->id.':'.BookingStatusNotification::eventFor($booking);

            if (in_array($key, $existing, true)) {
                continue;
            }

            $user->notify(new BookingStatusNotification($booking));
            $existing[] = $key;
        }
    }

    private function fromBookings(User $user): JsonResponse
    {
        $notices = $this->bookingsFor($user)
            ->with('boat')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (BoatBooking $booking) use ($user) {
                $notice = (new BookingStatusNotification($booking))->toArray($user);
                $created = $booking->updated_at ?? $booking->created_at ?? now();

                return [
                    'id' => 'booking-'.$booking->id.'-'.$notice['event'],
                    'kind' => $notice['kind'],
                    'event' => $notice['event'],
                    'booking_id' => $booking->id,
                    'title' => $notice['title'],
                    'body' => $notice['body'],
                    'unread' => in_array($notice['event'], ['pending', 'awaiting_payment', 'approved'], true),
                    'created_at' => $created->toIso8601String(),
                    'group' => $this->groupLabel($created),
                ];
            });

        return response()->json([
            'data' => $notices,
            'unread_count' => $notices->where('unread', true)->count(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<BoatBooking>
     */
    private function bookingsFor(User $user)
    {
        $query = BoatBooking::query()->withoutGlobalScope('organization');

        if ($user->isMobileOperator()) {
            $boatIds = Operator::managedBoatIdsForUser($user);

            return $query->whereIn('boat_id', $boatIds);
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(DatabaseNotification $notification): array
    {
        $created = $notification->created_at ?? now();

        return [
            'id' => $notification->id,
            'kind' => $notification->data['kind'] ?? 'booking',
            'event' => $notification->data['event'] ?? 'pending',
            'booking_id' => $notification->data['booking_id'] ?? null,
            'title' => $notification->data['title'] ?? 'Notification',
            'body' => $notification->data['body'] ?? '',
            'unread' => $notification->read_at === null,
            'created_at' => $created->toIso8601String(),
            'group' => $this->groupLabel($created),
        ];
    }

    private function groupLabel(Carbon $date): string
    {
        if ($date->isToday()) {
            return 'Today';
        }

        if ($date->isYesterday()) {
            return 'Yesterday';
        }

        return $date->format('M d, Y');
    }
}
