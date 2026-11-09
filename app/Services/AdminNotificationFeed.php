<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\AdminNotificationState;
use App\Models\Announcement;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BoatBookingActivity;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\RegularTrip;
use App\Models\User;
use App\Support\AdminNotificationPreferences;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdminNotificationFeed
{
    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     unread_count: int,
     *     counts: array{all: int, announcements: int, rentals: int, boats: int, organizations: int, weather: int, activity: int}
     * }
     */
    public function forUser(?User $user, int $limit = 40): array
    {
        if (! $user) {
            return [
                'items' => [],
                'unread_count' => 0,
                'counts' => $this->emptyCounts(),
            ];
        }

        $states = AdminNotificationState::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('source_key');

        $items = $this->sourceItems($user)
            ->unique('id')
            ->sortByDesc('at')
            ->values()
            ->map(function (array $item) use ($states) {
                /** @var AdminNotificationState|null $state */
                $state = $states->get($item['id']);

                if ($state?->deleted_at) {
                    return null;
                }

                $at = Carbon::parse($item['at']);
                $item['unread'] = $state?->read_at === null;
                $item['occurred_at'] = $at->toIso8601String();
                $item['date_label'] = $at->format('M d, Y');
                $item['time_label'] = $at->format('g:i A');
                unset($item['at']);

                return $item;
            })
            ->filter()
            ->values();

        $visible = $items->take($limit)->values();

        return [
            'items' => $visible->all(),
            'unread_count' => $visible->where('unread', true)->count(),
            'counts' => [
                'all' => $visible->count(),
                'announcements' => $visible->where('group', 'announcements')->count(),
                'rentals' => $visible->where('group', 'rentals')->count(),
                'boats' => $visible->where('group', 'boats')->count(),
                'organizations' => $visible->where('group', 'organizations')->count(),
                'weather' => $visible->where('group', 'weather')->count(),
                'activity' => $visible->where('group', 'activity')->count(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function sourceKeysForUser(User $user): array
    {
        return $this->sourceItems($user)->pluck('id')->unique()->values()->all();
    }

    public function rentalSidebarBadgeCount(?User $user): int
    {
        if (! $user || $user->isSuperAdmin()) {
            return 0;
        }

        return $this->actionableUnreadRentals($user)->count();
    }

    /**
     * Booking ids that still have an unread rental notification the admin has not opened.
     *
     * @return list<int>
     */
    public function unreadActionableBookingIds(?User $user): array
    {
        if (! $user || $user->isSuperAdmin()) {
            return [];
        }

        return $this->actionableUnreadRentals($user)
            ->map(function (array $item): ?int {
                if (preg_match('#/bookings/requests/(\d+)#', (string) ($item['href'] ?? ''), $matches) !== 1) {
                    return null;
                }

                return (int) $matches[1];
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function acknowledgeBooking(?User $user, int $bookingId): void
    {
        if (! $user || $user->isSuperAdmin() || $bookingId < 1) {
            return;
        }

        $keys = $this->sourceItems($user)
            ->filter(fn (array $item): bool => ($item['href'] ?? '') === '/bookings/requests/'.$bookingId)
            ->pluck('id')
            ->push('rental-seen-'.$bookingId);

        foreach ($keys as $key) {
            $state = AdminNotificationState::query()->firstOrNew([
                'user_id' => $user->id,
                'source_key' => (string) $key,
            ]);
            $state->read_at ??= now();
            $state->save();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actionableUnreadRentals(User $user): Collection
    {
        $states = AdminNotificationState::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('source_key');

        return $this->sourceItems($user)
            ->filter(function (array $item) use ($states): bool {
                $id = (string) ($item['id'] ?? '');
                $actionable = str_starts_with($id, 'booking-')
                    || str_starts_with($id, 'payment-')
                    || str_starts_with($id, 'reschedule-')
                    || str_ends_with($id, '-pending') && str_starts_with($id, 'operator-no-show-')
                    || str_ends_with($id, '-disputed') && str_starts_with($id, 'passenger-no-show-');

                if (! $actionable || ($item['group'] ?? '') !== 'rentals') {
                    return false;
                }

                /** @var AdminNotificationState|null $state */
                $state = $states->get($id);

                if ($state?->deleted_at) {
                    return false;
                }

                return $state?->read_at === null;
            })
            ->values();
    }

    private function unreadGroupCount(User $user, string $group): int
    {
        $states = AdminNotificationState::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('source_key');

        return $this->sourceItems($user)
            ->filter(fn (array $item): bool => ($item['group'] ?? '') === $group)
            ->filter(function (array $item) use ($states): bool {
                /** @var AdminNotificationState|null $state */
                $state = $states->get($item['id']);

                if ($state?->deleted_at) {
                    return false;
                }

                return $state?->read_at === null;
            })
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceItems(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return $this->platformItems();
        }

        if (! $user->organization_id) {
            return collect();
        }

        $preferences = AdminNotificationPreferences::for($user);

        return $this->barangayItems($user)
            ->filter(function (array $item) use ($preferences): bool {
                $key = $this->preferenceKey($item);

                return $key === null || ($preferences[$key] ?? true);
            })
            ->values();
    }

    private function preferenceKey(array $item): ?string
    {
        $id = (string) ($item['id'] ?? '');

        return match (true) {
            str_starts_with($id, 'booking-') => 'new_rental_requests',
            str_starts_with($id, 'payment-'), str_starts_with($id, 'activity-payment-') => 'payment_confirmation',
            str_starts_with($id, 'operator-no-show-') => 'operator_no_show',
            str_starts_with($id, 'passenger-no-show-') => 'passenger_no_show',
            str_starts_with($id, 'reschedule-'), str_starts_with($id, 'activity-refund-') => 'cancellation_refund',
            str_starts_with($id, 'activity-operator-') => 'operator_approval',
            str_starts_with($id, 'upcoming-') => 'upcoming_trip_reminders',
            str_starts_with($id, 'readiness-') => 'operator_readiness',
            str_starts_with($id, 'activity-weather-') => 'weather_alerts',
            str_starts_with($id, 'announcement-') && in_array($item['type'] ?? null, ['weather', 'suspension', 'emergency'], true) => 'weather_alerts',
            default => null,
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rentalActivityItems(int $organizationId): Collection
    {
        $groups = [
            'operator' => ['operator_accepted'],
            'payment' => ['xendit_payment_verified', 'xendit_full_rental_paid', 'xendit_balance_paid', 'payment_verified', 'balance_paid'],
            'refund' => ['cancelled', 'refund_chosen', 'refund_reschedule_chosen', 'refund_started', 'refund_completed', 'operator_no_show_refund_completed'],
            'weather' => ['weather_hold'],
        ];
        $groupFor = fn (string $event): ?string => collect($groups)->search(fn (array $events) => in_array($event, $events, true)) ?: null;

        return BoatBookingActivity::query()
            ->whereIn('event', array_merge(...array_values($groups)))
            ->where('created_at', '>=', now()->subDays(3))
            ->whereHas('booking', fn ($query) => $query->where('organization_id', $organizationId))
            ->with('booking')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(function (BoatBookingActivity $activity) use ($groupFor): ?array {
                $booking = $activity->booking;
                $group = $groupFor((string) $activity->event);
                if (! $booking || ! $group) {
                    return null;
                }

                $at = $activity->created_at ?? now();
                [$title, $typeLabel] = match ($activity->event) {
                    'operator_accepted' => ['Operator accepted a rental', 'Operator Approval'],
                    'xendit_payment_verified', 'payment_verified' => ['Down payment received by SafeSakay', 'Payment'],
                    'xendit_full_rental_paid' => ['Full payment received by SafeSakay', 'Payment'],
                    'xendit_balance_paid', 'balance_paid' => ['Remaining balance paid', 'Payment'],
                    'cancelled' => ['Rental cancelled', 'Cancellation'],
                    'refund_chosen' => ['Passenger chose a refund', 'Refund'],
                    'refund_reschedule_chosen' => ['Passenger chose a new date', 'Reschedule'],
                    'refund_started' => ['Refund sent to Xendit', 'Refund'],
                    'refund_completed', 'operator_no_show_refund_completed' => ['Refund completed', 'Refund'],
                    'weather_hold' => ['Rental put on weather hold', 'Weather'],
                    default => [(string) ($activity->title ?: 'Rental update'), 'Rental'],
                };

                return $this->item(
                    id: 'activity-'.$group.'-'.$activity->id,
                    type: $group === 'weather' ? 'weather' : ($group === 'refund' ? 'cancellation' : 'booking'),
                    group: $group === 'weather' ? 'weather' : 'rentals',
                    title: $title,
                    body: trim(($booking->customer_name ?: 'Passenger').' · '.$booking->code, ' ·'),
                    href: '/bookings/requests/'.$booking->id,
                    at: $at,
                    typeLabel: $typeLabel,
                    footer: $at->format('M d, Y g:i A'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function upcomingTripItems(int $organizationId): Collection
    {
        $today = now('Asia/Manila')->toDateString();
        $tomorrow = now('Asia/Manila')->addDay()->toDateString();

        return BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->where('status', BoatBookingStatus::Confirmed)
            ->whereBetween('booking_date', [$today, $tomorrow])
            ->with('boat')
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->limit(20)
            ->get()
            ->flatMap(function (BoatBooking $booking) use ($tomorrow): array {
                $date = $booking->booking_date?->toDateString();
                $time = $booking->start_time ? Carbon::parse($booking->start_time)->format('g:i A') : '';
                $at = now()->startOfDay();
                $rows = [$this->item(
                    id: 'upcoming-'.$booking->id.'-'.$date,
                    type: 'schedule',
                    group: 'rentals',
                    title: $date === $tomorrow ? 'Rental trip tomorrow' : 'Rental trip today',
                    body: trim(($booking->boat?->name ?? 'Boat').' · '.$booking->customer_name.' · '.$time, ' ·'),
                    href: '/bookings/requests/'.$booking->id,
                    at: $at,
                    typeLabel: 'Upcoming Trip',
                    footer: $booking->booking_date?->format('M d, Y').' · '.$time,
                )];

                if ($date === $tomorrow && $booking->operator_availability_confirmed_at === null) {
                    $rows[] = $this->item(
                        id: 'readiness-'.$booking->id.'-'.$date,
                        type: 'delay',
                        group: 'rentals',
                        title: 'Operator has not confirmed readiness',
                        body: trim(($booking->boat?->name ?? 'Boat').' · '.$booking->code.' · tomorrow '.$time, ' ·'),
                        href: '/bookings/requests/'.$booking->id,
                        at: $at,
                        typeLabel: 'Readiness',
                        footer: 'Trip on '.$booking->booking_date?->format('M d, Y'),
                    );
                }

                return $rows;
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function platformItems(): Collection
    {
        $items = collect();

        Organization::query()
            ->tenants()
            ->whereNotNull('restore_requested_at')
            ->where('is_active', false)
            ->with(['restoreRequester'])
            ->latest('restore_requested_at')
            ->limit(20)
            ->get()
            ->each(function (Organization $organization) use ($items): void {
                $at = $organization->restore_requested_at ?? now();
                $requester = $organization->restoreRequester;

                $items->push($this->item(
                    id: 'org-restore-'.$organization->id,
                    type: 'delay',
                    group: 'organizations',
                    title: $organization->isArchived() ? 'Reactivation request' : 'Restore request',
                    body: $organization->isArchived()
                        ? $organization->name.' asked to reactivate an archived organization.'
                        : $organization->name.' asked to restore access.',
                    href: '/platform/organization-requests',
                    at: $at,
                    typeLabel: $organization->isArchived() ? 'Reactivation Request' : 'Restore Request',
                    subtitle: $requester?->name
                        ? $requester->name.($requester->email ? ' · '.$requester->email : '')
                        : 'Barangay admin appeal',
                    footer: 'Requested on: '.$at->format('M d, Y g:i A'),
                ));
            });

        Organization::query()
            ->tenants()
            ->whereNotNull('closure_requested_at')
            ->where('is_active', true)
            ->with(['closureRequester'])
            ->latest('closure_requested_at')
            ->limit(20)
            ->get()
            ->each(function (Organization $organization) use ($items): void {
                $at = $organization->closure_requested_at ?? now();
                $requester = $organization->closureRequester;

                $items->push($this->item(
                    id: 'org-closure-'.$organization->id,
                    type: 'delay',
                    group: 'organizations',
                    title: 'Closure request',
                    body: $organization->name.' asked to close their SafeSakay account.',
                    href: '/platform/organization-requests',
                    at: $at,
                    typeLabel: 'Closure Request',
                    subtitle: $requester?->name
                        ? $requester->name.($requester->email ? ' · '.$requester->email : '')
                        : ($organization->closure_reason ?: 'Barangay admin request'),
                    footer: 'Requested on: '.$at->format('M d, Y g:i A'),
                ));
            });

        Organization::query()
            ->tenants()
            ->whereNull('approved_at')
            ->whereNull('suspended_at')
            ->with(['users' => fn ($users) => $users->where('role', UserRole::Administrator)->orderBy('id')])
            ->latest('id')
            ->limit(20)
            ->get()
            ->each(function (Organization $organization) use ($items): void {
                $admin = $organization->users->first();
                $at = $organization->created_at ?? now();

                $items->push($this->item(
                    id: 'org-pending-'.$organization->id,
                    type: 'booking',
                    group: 'organizations',
                    title: 'New organization registration',
                    body: trim($organization->name.' · '.$organization->address, ' ·'),
                    href: '/platform/organizations/'.$organization->id,
                    at: $at,
                    typeLabel: 'Pending Approval',
                    subtitle: $admin?->name ? $admin->name.($admin->email ? ' · '.$admin->email : '') : 'Waiting for Super Admin review',
                    footer: 'Submitted on: '.$at->format('M d, Y g:i A'),
                ));
            });

        PlatformEvent::query()
            ->with('actor')
            ->latest('id')
            ->limit(40)
            ->get()
            ->each(function (PlatformEvent $event) use ($items): void {
                $at = $event->created_at ?? now();
                $href = $event->organization_id
                    ? '/platform/organizations/'.$event->organization_id
                    : '/platform/audit-logs';

                $items->push($this->item(
                    id: 'platform-event-'.$event->id,
                    type: $this->platformEventType($event->action),
                    group: 'activity',
                    title: $this->platformEventTitle($event),
                    body: $this->platformEventBody($event),
                    href: $href,
                    at: $at,
                    typeLabel: $event->actionLabel(),
                    subtitle: $event->actor?->name ? 'By '.$event->actor->name : 'Platform',
                    footer: $at->format('M d, Y g:i A'),
                ));
            });

        return $items
            ->filter(fn (array $item): bool => PlatformSettings::notifies(match (true) {
                str_starts_with((string) $item['id'], 'org-pending-') => 'organization_registration',
                str_starts_with((string) $item['id'], 'platform-event-') => 'system_activity',
                default => 'approval_requests',
            }))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function barangayItems(User $user): Collection
    {
        $items = collect();
        $orgName = $user->organization?->name;
        $organizationId = $user->organization_id;

        Announcement::query()
            ->where('organization_id', $organizationId)
            ->with('author')
            ->latest('published_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->each(function (Announcement $announcement) use ($items, $orgName) {
                $audience = collect($announcement->audience ?? [])
                    ->map(fn (string $role) => UserRole::tryFrom($role)?->label() ?? $role)
                    ->filter()
                    ->values()
                    ->all();

                $footerParts = collect([
                    $audience !== [] ? 'Sent to: '.implode(', ', $audience) : null,
                    $announcement->recipient_count
                        ? $announcement->recipient_count.' '.($announcement->recipient_count === 1 ? 'recipient' : 'recipients')
                        : null,
                ])->filter();

                $type = $announcement->category ?: 'general';

                $items->push($this->item(
                    id: 'announcement-'.$announcement->id,
                    type: $type,
                    group: 'announcements',
                    title: $announcement->title,
                    body: $announcement->body,
                    href: '/announcements',
                    at: $announcement->published_at ?? $announcement->created_at ?? now(),
                    typeLabel: $announcement->categoryLabel(),
                    footer: $footerParts->implode(' · '),
                    subtitle: $orgName,
                ));
            });

        \App\Models\OperatorNoShowReport::query()
            ->whereHas('booking', fn ($query) => $query->where('organization_id', $organizationId))
            ->where(function ($query): void {
                $query->where('status', \App\Enums\OperatorNoShowReportStatus::Pending)
                    ->orWhere(fn ($inner) => $inner
                        ->where('status', \App\Enums\OperatorNoShowReportStatus::Verified)
                        ->whereNull('resolved_at')
                        ->whereIn('replacement_offer_status', [
                            \App\Models\OperatorNoShowReport::OFFER_DECLINED,
                            \App\Models\OperatorNoShowReport::OFFER_AWAITING_PASSENGER,
                        ]));
            })
            ->with('booking')
            ->latest('id')
            ->limit(15)
            ->get()
            ->each(function (\App\Models\OperatorNoShowReport $report) use ($items) {
                $booking = $report->booking;
                $pending = $report->status === \App\Enums\OperatorNoShowReportStatus::Pending;
                $at = $pending ? ($report->reported_at ?? $report->created_at ?? now()) : ($report->replacement_responded_at ?? $report->updated_at ?? now());

                $items->push($this->item(
                    id: 'operator-no-show-'.$report->id.'-'.($pending ? 'pending' : $report->replacement_offer_status),
                    type: 'booking',
                    group: 'rentals',
                    title: $pending ? 'Boat or operator no-show reported' : (string) $report->replacementOfferLabel(),
                    body: trim(($booking?->customer_name ?? 'Passenger').' · '.($booking?->code ?? '').' · '.($report->reasonLabel() ?? 'Did not arrive'), ' ·'),
                    href: '/bookings/requests/'.$report->boat_booking_id,
                    at: $at,
                    typeLabel: 'No-Show',
                    subtitle: $pending ? 'Needs review' : 'Replacement update',
                    footer: 'Reported on: '.($report->reported_at ?? $at)->format('M d, Y g:i A'),
                ));
            });

        \App\Models\PassengerNoShowReport::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [\App\Models\PassengerNoShowReport::STATUS_CONFIRMED, \App\Models\PassengerNoShowReport::STATUS_DISPUTED])
            ->where('reported_at', '>=', now()->subDays(7))
            ->with('booking')
            ->latest('id')
            ->limit(15)
            ->get()
            ->each(function (\App\Models\PassengerNoShowReport $report) use ($items) {
                $disputed = $report->status === \App\Models\PassengerNoShowReport::STATUS_DISPUTED;
                $at = $disputed ? ($report->disputed_at ?? now()) : ($report->reported_at ?? now());

                $items->push($this->item(
                    id: 'passenger-no-show-'.$report->id.'-'.$report->status,
                    type: 'booking',
                    group: 'rentals',
                    title: $disputed ? 'Passenger disputed a no-show' : 'Passenger no-show recorded',
                    body: trim(($report->booking?->customer_name ?? 'Passenger').' · '.($report->booking?->code ?? ''), ' ·'),
                    href: '/bookings/requests/'.$report->boat_booking_id,
                    at: $at,
                    typeLabel: 'No-Show',
                    subtitle: $disputed ? 'Needs review' : 'Rental cancelled, no refund',
                    footer: ($disputed ? 'Disputed on: ' : 'Reported on: ').$at->timezone('Asia/Manila')->format('M d, Y g:i A'),
                ));
            });

        BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->where('status', BoatBookingStatus::ReschedulePending)
            ->latest()
            ->limit(15)
            ->get()
            ->each(function (BoatBooking $booking) use ($items) {
                $at = $booking->updated_at ?? now();

                $items->push($this->item(
                    id: 'reschedule-'.$booking->id,
                    type: 'booking',
                    group: 'rentals',
                    title: 'Rental reschedule request',
                    body: trim($booking->customer_name.' · '.$booking->code.' · requested '.$booking->requested_booking_date?->format('M d, Y'), ' ·'),
                    href: '/bookings/requests/'.$booking->id,
                    at: $at,
                    typeLabel: 'Reschedule',
                    subtitle: 'Waiting for approval',
                    footer: 'Requested on: '.$at->format('M d, Y g:i A'),
                ));
            });

        BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->where('status', BoatBookingStatus::Pending)
            ->latest()
            ->limit(15)
            ->get()
            ->each(function (BoatBooking $booking) use ($items) {
                $requested = $booking->created_at ?? now();

                $items->push($this->item(
                    id: 'booking-'.$booking->id,
                    type: 'booking',
                    group: 'rentals',
                    title: 'New rental request',
                    body: trim($booking->customer_name.' · '.($booking->destination ?: $booking->package), ' ·'),
                    href: '/bookings/requests/'.$booking->id,
                    at: $requested,
                    typeLabel: 'Rental Request',
                    subtitle: 'Pending approval',
                    footer: 'Requested on: '.$requested->format('M d, Y g:i A'),
                ));
            });

        BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query): void {
                $query
                    ->where('payment_status', BookingPaymentStatus::ForVerification)
                    ->orWhere(function ($unpaid): void {
                        $unpaid
                            ->where('payment_status', BookingPaymentStatus::Unpaid)
                            ->where(function ($proof): void {
                                $proof
                                    ->whereNotNull('gcash_reference')
                                    ->orWhereNotNull('payment_receipt_path')
                                    ->orWhereNotNull('payment_submitted_at');
                            });
                    });
            })
            ->latest('payment_submitted_at')
            ->latest('id')
            ->limit(15)
            ->get()
            ->each(function (BoatBooking $booking) use ($items) {
                if ($booking->paymentStatus() !== BookingPaymentStatus::ForVerification) {
                    return;
                }

                $at = $booking->payment_submitted_at ?? $booking->updated_at ?? now();

                $items->push($this->item(
                    id: 'payment-'.$booking->id,
                    type: 'booking',
                    group: 'rentals',
                    title: 'Down payment for verification',
                    body: trim($booking->customer_name.' · '.($booking->destination ?: $booking->package), ' ·'),
                    href: '/bookings/requests/'.$booking->id,
                    at: $at,
                    typeLabel: 'Payment',
                    subtitle: 'Waiting for operator verification',
                    footer: 'Submitted on: '.$at->format('M d, Y g:i A'),
                ));
            });

        Boat::query()
            ->where('organization_id', $organizationId)
            ->where('status', BoatStatus::Maintenance)
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->each(function (Boat $boat) use ($items) {
                $at = $boat->updated_at ?? now();
                $label = $boat->code ?: $boat->name;
                $nameSuffix = $boat->code && $boat->name ? ' ('.$boat->name.')' : '';

                $items->push($this->item(
                    id: 'maintenance-'.$boat->id,
                    type: 'maintenance',
                    group: 'boats',
                    title: 'Engine check scheduled',
                    body: 'Regular engine check scheduled for '.$label.$nameSuffix.'.',
                    href: '/boats',
                    at: $at,
                    typeLabel: 'Maintenance',
                    footer: 'Scheduled on: '.$at->format('M d, Y g:i A'),
                ));
            });

        RegularTrip::query()
            ->where('organization_id', $organizationId)
            ->with('boat')
            ->latest('id')
            ->limit(20)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->take(10)
            ->each(function (RegularTrip $trip) use ($items) {
                $at = $trip->created_at ?? $trip->trip_date ?? now();
                $boatName = $trip->boat?->name ?: 'A boat';
                $direction = $trip->direction ?: $trip->route;

                $items->push($this->item(
                    id: 'schedule-'.$trip->id,
                    type: 'schedule',
                    group: 'boats',
                    title: 'Boat assigned to trip',
                    body: $boatName.' has been assigned to a regular trip ('.$direction.').',
                    href: '/regular-trips/'.$trip->id,
                    at: $at,
                    typeLabel: 'Schedule',
                    footer: 'Assigned on: '.$at->format('M d, Y g:i A'),
                ));
            });

        RegularTrip::query()
            ->where('organization_id', $organizationId)
            ->with('boat')
            ->whereNotNull('boarding_wait_notified_at')
            ->latest('boarding_wait_notified_at')
            ->limit(10)
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound() && $trip->status->isWaiting())
            ->each(function (RegularTrip $trip) use ($items): void {
                $at = $trip->boarding_wait_notified_at ?? now();
                $boatName = $trip->boat?->name ?: 'A boat';
                $minutes = $trip->boardingWaitMinutes();

                $items->push($this->item(
                    id: 'boarding-wait-'.$trip->id,
                    type: 'schedule',
                    group: 'boats',
                    title: 'Waiting limit reached',
                    body: $boatName.' reached the '.$minutes.'-minute boarding wait. Review passengers and confirm departure if it is safe. The boat will not leave automatically.',
                    href: '/regular-trips/'.$trip->id,
                    at: $at,
                    typeLabel: 'Boarding',
                    footer: 'Reached on: '.$at->format('M d, Y g:i A'),
                ));
            });

        $items = $items->merge($this->rentalActivityItems((int) $organizationId))->merge($this->upcomingTripItems((int) $organizationId));

        return $items;
    }

    /**
     * @return array{all: int, announcements: int, rentals: int, boats: int, organizations: int, weather: int, activity: int}
     */
    private function emptyCounts(): array
    {
        return [
            'all' => 0,
            'announcements' => 0,
            'rentals' => 0,
            'boats' => 0,
            'organizations' => 0,
            'weather' => 0,
            'activity' => 0,
        ];
    }

    private function platformEventType(string $action): string
    {
        return match ($action) {
            'suspended', 'deactivated', 'admin_deactivated', 'rejected', 'billing_locked', 'restore_requested', 'closure_requested', 'reactivation_requested' => 'delay',
            'approved', 'onboarded', 'restored', 'admin_restored', 'restore_request_dismissed', 'closure_rejected', 'archived', 'reactivation_rejected' => 'general',
            default => 'schedule',
        };
    }

    private function platformEventTitle(PlatformEvent $event): string
    {
        $name = $event->organization_name;

        return match ($event->action) {
            'approved' => $name.' was approved',
            'suspended' => $name.' was suspended',
            'deactivated' => $name.' was deactivated',
            'admin_deactivated' => $name.' admin was deactivated',
            'admin_restored' => $name.' admin was restored',
            'restored' => $name.' was restored',
            'restore_requested' => $name.' requested restore',
            'restore_request_dismissed' => $name.' restore request was dismissed',
            'closure_requested' => $name.' requested account closure',
            'closure_rejected' => $name.' closure request was rejected',
            'archived' => $name.' was archived',
            'reactivation_requested' => $name.' requested reactivation',
            'reactivation_rejected' => $name.' reactivation request was rejected',
            'rejected' => $name.' registration was rejected',
            'onboarded' => $name.' was onboarded',
            'billing_locked' => $name.' locked (payment violation)',
            'settings_updated' => 'System settings updated',
            default => $name.' · '.$event->actionLabel(),
        };
    }

    private function platformEventBody(PlatformEvent $event): string
    {
        if (filled($event->note)) {
            return $event->note;
        }

        return match ($event->action) {
            'approved' => 'This barangay can now sign in and operate SafeSakay.',
            'suspended' => 'Access is paused until Super Admin restores it.',
            'deactivated' => 'This barangay is unused or temporarily inactive until Super Admin restores it.',
            'admin_deactivated' => 'That administrator cannot sign in until Super Admin restores the account.',
            'admin_restored' => 'That administrator can sign in again.',
            'restored' => 'Access is active again.',
            'restore_requested' => 'The barangay admin asked Super Admin to restore access.',
            'restore_request_dismissed' => 'The restore request was closed. The organization stays suspended.',
            'closure_requested' => 'The barangay admin asked Super Admin to close this organization.',
            'closure_rejected' => 'The closure request was rejected. The organization stays active.',
            'archived' => 'This barangay is archived. Historical records stay saved.',
            'reactivation_requested' => 'The barangay admin asked Super Admin to restore this archived organization.',
            'reactivation_rejected' => 'The reactivation request was rejected. The organization stays archived.',
            'rejected' => 'This pending registration was not approved.',
            'onboarded' => 'The barangay admin account is ready to sign in.',
            'billing_locked' => 'Operators and passengers cannot use the system until the monthly plan is verified or waived.',
            default => $event->actionLabel(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $id,
        string $type,
        string $group,
        string $title,
        string $body,
        string $href,
        mixed $at,
        string $typeLabel,
        ?string $subtitle = null,
        ?string $footer = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'tone' => $this->tone($type),
            'group' => $group,
            'type_label' => $typeLabel,
            'title' => $title,
            'body' => $body,
            'subtitle' => $subtitle,
            'footer' => $footer,
            'href' => $href,
            'at' => $at instanceof Carbon ? $at->toIso8601String() : Carbon::parse((string) $at)->toIso8601String(),
        ];
    }

    private function tone(string $type): string
    {
        return match ($type) {
            'delay', 'cancellation', 'weather', 'emergency' => 'delay',
            'booking' => 'rental',
            'maintenance', 'safety' => 'maintenance',
            'schedule', 'conflict' => 'schedule',
            default => 'general',
        };
    }
}
