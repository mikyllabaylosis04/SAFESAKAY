<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\RentalRefundRequest;
use App\Services\AdminNotificationFeed;
use App\Support\PasswordPolicy;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('organization');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'platform' => PlatformSettings::branding(),
            'auth' => [
                'user' => $user,
                'organization' => $user?->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'archived' => $user->organization->isArchived(),
                ] : null,
                'is_super_admin' => $user?->isSuperAdmin() ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'pendingBookingRequests' => $user?->isSuperAdmin()
                ? 0
                : app(AdminNotificationFeed::class)->rentalSidebarBadgeCount($user),
            'pendingPaymentActions' => $user && ! $user->isSuperAdmin() && $user->organization_id
                ? RentalRefundRequest::query()
                    ->where('organization_id', $user->organization_id)
                    ->whereIn('status', [RentalRefundRequest::STATUS_PENDING_REVIEW, RentalRefundRequest::STATUS_FAILED])
                    ->count()
                : 0,
            'pendingOrganizationApprovals' => $user?->isSuperAdmin()
                ? Organization::query()->tenants()->whereNull('approved_at')->whereNull('suspended_at')->count()
                : 0,
            'pendingRestoreRequests' => $user?->isSuperAdmin()
                ? Organization::query()
                    ->tenants()
                    ->whereNotNull('restore_requested_at')
                    ->where('is_active', false)
                    ->where(fn ($query) => $query->whereNull('inactive_kind')->orWhere('inactive_kind', '!=', 'archived'))
                    ->count()
                : 0,
            'pendingClosureRequests' => $user?->isSuperAdmin()
                ? Organization::query()->tenants()->whereNotNull('closure_requested_at')->where('is_active', true)->count()
                : 0,
            'pendingReactivationRequests' => $user?->isSuperAdmin()
                ? Organization::query()
                    ->tenants()
                    ->whereNotNull('restore_requested_at')
                    ->where('is_active', false)
                    ->where('inactive_kind', 'archived')
                    ->count()
                : 0,
            'headerNotifications' => app(AdminNotificationFeed::class)->forUser($user, 25),
            'passwordHint' => PasswordPolicy::HINT,
        ];
    }
}
