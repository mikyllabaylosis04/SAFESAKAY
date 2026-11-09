<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\WeatherSuspensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $announcements = Announcement::query()
            ->with(['author', 'organization', 'weatherSuspension.lifter'])
            ->latest('published_at')
            ->latest('id')
            ->get();

        return Inertia::render('announcements/Index', [
            'announcements' => $announcements->map(fn (Announcement $announcement) => $this->transform($announcement)),
            'weatherSuspensions' => WeatherSuspensionController::records(),
            'weatherRouteOptions' => WeatherSuspensionController::routeOptions(),
            'stats' => [
                'total' => $announcements->count(),
                'weather' => $announcements->where('category', 'weather')->count(),
                'today' => $announcements
                    ->filter(fn (Announcement $announcement) => $announcement->published_at?->isToday())
                    ->count(),
                'recipients' => (int) $announcements->sum('recipient_count'),
            ],
        ]);
    }

    public function store(StoreAnnouncementRequest $request, WeatherSuspensionService $weather): RedirectResponse
    {
        $data = $request->validated();
        $audience = array_values(array_unique($data['audience']));

        if ($data['category'] === 'weather') {
            $weather->declare($request->user(), [
                'reason' => $data['reason'] ?? WeatherHoldReason::BadWeather->value,
                'affected_date' => $data['affected_date'] ?? now('Asia/Manila')->toDateString(),
                'affected_routes' => $data['affected_routes'] ?? ['all'],
                'duration_type' => $data['duration_type'] ?? 'whole_day',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'announcement_message' => $data['body'],
                'title' => $data['title'],
                'audience' => $audience,
            ]);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Weather notice posted. Affected trips are suspended. Verified payments were not refunded.',
            ]);

            return to_route('announcements.index');
        }

        $announcement = Announcement::query()->create([
            'user_id' => $request->user()?->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'],
            'audience' => $audience,
            'published_at' => now(),
            'recipient_count' => 0,
        ]);

        $recipients = $this->recipients($request->user()?->organization_id, $audience);
        $count = $recipients->count();

        if ($count > 0) {
            Notification::send($recipients, new AnnouncementNotification($announcement));
        }

        $announcement->update(['recipient_count' => $count]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? 'Announcement sent to 1 recipient.'
                : "Announcement sent to {$count} recipients.",
        ]);

        return to_route('announcements.index');
    }

    /**
     * @param  list<string>  $audience
     * @return Collection<int, User>
     */
    private function recipients(?int $organizationId, array $audience): Collection
    {
        if (! $organizationId || $audience === []) {
            return collect();
        }

        $roles = collect($audience)
            ->flatMap(function (string $role) {
                $value = UserRole::from($role);

                return $value === UserRole::Operator
                    ? [UserRole::Operator, UserRole::Staff]
                    : [$value];
            })
            ->unique()
            ->all();

        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', $roles)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Announcement $announcement): array
    {
        $audience = collect($announcement->audience ?? [])
            ->map(fn (string $role) => UserRole::tryFrom($role)?->label() ?? $role)
            ->filter()
            ->values()
            ->all();

        $postedAt = $announcement->published_at ?? $announcement->created_at;
        $suspension = $announcement->weatherSuspension;

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'category' => $announcement->category,
            'category_label' => $announcement->categoryLabel(),
            'audience' => $announcement->audience ?? [],
            'audience_labels' => $audience,
            'recipient_count' => $announcement->recipient_count,
            'author_name' => $announcement->author?->name ?? 'Admin',
            'author_role' => $announcement->author?->role?->label(),
            'organization_name' => $announcement->organization?->name,
            'published_at' => $postedAt?->toIso8601String(),
            'published_at_label' => $postedAt?->format('M d, Y g:i A'),
            'posted_date_label' => $postedAt?->format('M d, Y'),
            'posted_time_label' => $postedAt?->format('g:i A'),
            'posted_ago' => $postedAt?->diffForHumans(),
            'sent_today' => $postedAt?->isToday() ?? false,
            'status' => $suspension ? $suspension->status->value : 'posted',
            'status_label' => $suspension ? $suspension->status->label() : 'Posted',
            'weather' => $suspension ? [
                'id' => $suspension->id,
                'code' => $suspension->code,
                'reason_label' => $suspension->reasonLabel(),
                'affected_date_label' => $suspension->affected_date?->format('l, M d, Y'),
                'duration_label' => $suspension->durationLabel(),
                'routes_label' => $suspension->affectedRoutesLabel(),
                'affected_trips_count' => (int) $suspension->affected_trips_count,
                'affected_rentals_count' => (int) $suspension->affected_rentals_count,
                'lifted_at_label' => $suspension->lifted_at?->format('M d, Y g:i A'),
                'lifted_by_name' => $suspension->lifter?->name,
            ] : null,
        ];
    }
}
