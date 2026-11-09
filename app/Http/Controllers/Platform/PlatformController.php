<?php

namespace App\Http\Controllers\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformOrganizationRequest;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\RegularTrip;
use App\Models\RentalCommission;
use App\Models\User;
use App\Services\OrganizationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformController extends Controller
{
    public function __construct(private OrganizationAccessService $access)
    {
    }

    public function dashboard(): Response
    {
        $organizations = Organization::query()
            ->tenants()
            ->withCount(['boats', 'users'])
            ->orderBy('name')
            ->get();

        return Inertia::render('platform/Dashboard', [
            'stats' => [
                'organizations' => $organizations->count(),
                'pending' => $organizations->filter(fn (Organization $organization) => $organization->isPending())->count(),
                'active' => $organizations->where('is_active', true)->count(),
                'deactivated' => $organizations->filter(fn (Organization $organization) => $organization->isAccessPaused())->count(),
                'boats' => Boat::query()->withoutGlobalScope('organization')->count(),
                'admins' => User::query()
                    ->where('role', UserRole::Administrator)
                    ->whereNotNull('organization_id')
                    ->count(),
                'rentals' => BoatBooking::query()->withoutGlobalScope('organization')->count(),
                'operators' => User::query()->where('role', UserRole::Operator)->count(),
                'passengers' => User::query()->where('role', UserRole::Passenger)->count(),
                'commission' => round((float) RentalCommission::query()
                    ->withoutGlobalScope('organization')
                    ->whereNull('commission_reversed_at')
                    ->sum('commission_amount'), 2),
            ],
            'organizations' => $organizations
                ->take(8)
                ->map(fn (Organization $organization) => $this->transformOrganization($organization))
                ->values(),
            'recent_events' => $this->transformEvents(
                PlatformEvent::query()->with('actor')->latest('id')->limit(8)->get(),
            ),
        ]);
    }

    public function organizations(): Response
    {
        $organizations = Organization::query()
            ->tenants()
            ->withCount(['boats', 'users'])
            ->orderBy('name')
            ->get();

        $latestChanges = $this->latestAccessChangesByOrganization();

        return Inertia::render('platform/Organizations', [
            'organizations' => $organizations
                ->map(fn (Organization $organization) => $this->transformOrganization(
                    $organization,
                    $latestChanges->get($organization->id),
                ))
                ->values(),
        ]);
    }

    public function organizationRequests(): Response
    {
        return Inertia::render('platform/OrganizationRequests', [
            'closure_requests' => $this->closureRequestsQuery()
                ->get()
                ->map(fn (Organization $organization) => $this->transformClosureRequest($organization))
                ->values(),
            'restore_requests' => $this->restoreRequestsQuery()
                ->map(fn (Organization $organization) => $this->transformRestoreRequest($organization))
                ->values(),
            'reactivation_requests' => $this->reactivationRequestsQuery()
                ->map(fn (Organization $organization) => $this->transformReactivationRequest($organization))
                ->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/Onboard', [
            'takenLocations' => Organization::query()
                ->tenants()
                ->get(['name', 'address'])
                ->map(fn (Organization $organization) => [
                    'name' => $organization->name,
                    'address' => $organization->address,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StorePlatformOrganizationRequest $request): RedirectResponse
    {
        $organization = $this->access->onboard($request->user(), $request->safe()->only([
            'organization_name',
            'organization_address',
            'contact_number',
            'name',
            'email',
            'password',
        ]));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->name.' is live. Their admin can sign in now.',
        ]);

        return to_route('platform.organizations.show', $organization);
    }

    public function show(Organization $organization): Response
    {
        $organization = $this->tenant($organization);

        $organization->loadCount(['boats', 'users']);
        $organization->load(['restoreRequester', 'closureRequester']);

        $accessHistory = PlatformEvent::query()
            ->with('actor')
            ->where('organization_id', $organization->id)
            ->whereIn('action', PlatformEvent::accessHistoryActions())
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('platform/OrganizationShow', [
            'organization' => [
                ...$this->transformOrganization($organization, $accessHistory->first()),
                'tenant_id' => $organization->tenant_id,
                'created_at_label' => $organization->created_at?->format('M d, Y'),
                'suspended_at_label' => $organization->suspended_at?->format('M d, Y · g:i A'),
                'suspended_reason' => $organization->suspended_reason,
                'restore_requested' => $organization->hasPendingRestoreRequest(),
                'restore_requested_at_label' => $organization->restore_requested_at?->format('M d, Y · g:i A'),
                'restore_request_reason' => $organization->restore_request_reason,
                'restore_request_details' => $organization->restore_request_details,
                'restore_requested_by_name' => $organization->restoreRequester?->name,
                'restore_requested_by_email' => $organization->restoreRequester?->email,
                'closure_requested' => $organization->hasPendingClosureRequest(),
                'closure_requested_at_label' => $organization->closure_requested_at?->format('M d, Y · g:i A'),
                'closure_reason' => $organization->closure_reason,
                'closure_details' => $organization->closure_details,
                'closure_requested_by_name' => $organization->closureRequester?->name,
                'closure_requested_by_email' => $organization->closureRequester?->email,
                'trips_count' => RegularTrip::query()
                    ->withoutGlobalScope('organization')
                    ->where('organization_id', $organization->id)
                    ->count(),
                'rentals_count' => BoatBooking::query()
                    ->withoutGlobalScope('organization')
                    ->where('organization_id', $organization->id)
                    ->count(),
                'boats' => $organization->boats()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Boat $boat) => [
                        'id' => $boat->id,
                        'name' => $boat->name,
                        'code' => $boat->code,
                        'status_label' => $boat->status->label(),
                        'archived' => $boat->is_active === false,
                    ]),
                'admins' => $this->transformAdmins(
                    $organization->users()
                        ->where('role', UserRole::Administrator)
                        ->orderBy('name')
                        ->get(),
                ),
                'access_history' => $this->transformEvents($accessHistory),
            ],
        ]);
    }

    public function deactivate(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->access->deactivate($organization, $request->user(), $data['reason']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->name.' is deactivated. Their admin and operators cannot sign in until restored.',
        ]);

        return back();
    }

    public function approve(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);
        $this->access->approve($organization, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->name.' is approved. Their admin can now sign in.',
        ]);

        return back();
    }

    public function reject(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);
        $name = $organization->name;

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->access->reject($organization, $request->user(), $data['reason'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $name.' was rejected. They can register again with a different request.',
        ]);

        return to_route('platform.pending');
    }

    public function pending(): Response
    {
        $organizations = Organization::query()
            ->tenants()
            ->withCount(['boats', 'users'])
            ->get()
            ->filter(fn (Organization $organization) => $organization->isPending())
            ->sortBy('name')
            ->values();

        return Inertia::render('platform/Pending', [
            'organizations' => $organizations
                ->map(fn (Organization $organization) => $this->transformOrganization($organization))
                ->values(),
        ]);
    }

    public function admins(): Response
    {
        $admins = User::query()
            ->with('organization')
            ->where('role', UserRole::Administrator)
            ->whereNotNull('organization_id')
            ->whereHas('organization', fn ($query) => $query->tenants())
            ->orderBy('name')
            ->get();

        return Inertia::render('platform/Admins', [
            'admins' => $this->transformAdmins($admins)->values(),
        ]);
    }

    public function updateAdminStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required'],
            'reason' => $request->boolean('is_active')
                ? ['nullable', 'string', 'max:180']
                : ['required', 'string', 'min:3', 'max:180'],
        ]);

        $this->access->setAdministratorActive(
            $user,
            $request->user(),
            $request->boolean('is_active'),
            $data['reason'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->boolean('is_active')
                ? $user->name.' can sign in again.'
                : $user->name.' is deactivated and cannot sign in.',
        ]);

        return back();
    }

    public function restore(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);
        $this->access->restore($organization, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->name.' is active again.',
        ]);

        return back();
    }

    public function dismissRestoreRequest(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);
        $this->access->dismissRestoreRequest($organization, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->isArchived()
                ? 'Reactivation request rejected. '.$organization->name.' stays archived.'
                : 'Restore request dismissed. '.$organization->name.' stays deactivated.',
        ]);

        return back();
    }

    public function approveClosure(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);
        $this->access->approveClosure($organization, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $organization->name.' is archived. Historical records stay saved.',
        ]);

        return back();
    }

    public function rejectClosure(Request $request, Organization $organization): RedirectResponse
    {
        $organization = $this->tenant($organization);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->access->rejectClosure($organization, $request->user(), $data['reason'] ?? 'Closure request was rejected.');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Closure request rejected. '.$organization->name.' stays active.',
        ]);

        return back();
    }

    private function tenant(Organization $organization): Organization
    {
        abort_if($organization->isPlatformRecord(), 404);

        return $organization;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PlatformEvent>  $events
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function accessHistoryQuery()
    {
        return PlatformEvent::query()
            ->with('actor')
            ->whereIn('action', PlatformEvent::accessHistoryActions())
            ->latest('id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, PlatformEvent>
     */
    private function latestAccessChangesByOrganization()
    {
        $latestChangeIds = PlatformEvent::query()
            ->whereIn('action', PlatformEvent::accessHistoryActions())
            ->selectRaw('MAX(id) as id')
            ->groupBy('organization_id')
            ->pluck('id');

        return PlatformEvent::query()
            ->whereIn('id', $latestChangeIds)
            ->get()
            ->keyBy('organization_id');
    }

    private function closureRequestsQuery()
    {
        return Organization::query()
            ->tenants()
            ->with(['closureRequester'])
            ->withCount(['boats', 'users'])
            ->whereNotNull('closure_requested_at')
            ->where('is_active', true)
            ->latest('closure_requested_at');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    private function restoreRequestsQuery()
    {
        return Organization::query()
            ->tenants()
            ->with(['restoreRequester'])
            ->withCount(['boats', 'users'])
            ->whereNotNull('restore_requested_at')
            ->get()
            ->filter(fn (Organization $organization) => $organization->isAccessPaused() && ! $organization->isArchived())
            ->sortByDesc(fn (Organization $organization) => $organization->restore_requested_at?->timestamp ?? 0)
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    private function reactivationRequestsQuery()
    {
        return Organization::query()
            ->tenants()
            ->with(['restoreRequester'])
            ->withCount(['boats', 'users'])
            ->whereNotNull('restore_requested_at')
            ->get()
            ->filter(fn (Organization $organization) => $organization->isArchived())
            ->sortByDesc(fn (Organization $organization) => $organization->restore_requested_at?->timestamp ?? 0)
            ->values();
    }

    private function transformEvents($events)
    {
        return $events->map(fn (PlatformEvent $event) => [
            'id' => $event->id,
            'action' => $event->action,
            'action_label' => $event->actionLabel(),
            'organization_id' => $event->organization_id,
            'organization_name' => $event->organization_name,
            'actor_name' => $event->actor?->name,
            'note' => $event->note,
            'created_at_label' => $event->created_at?->format('M d, Y · g:i A'),
            'period' => $event->created_at?->format('Y-m'),
        ])->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $admins
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function transformAdmins($admins)
    {
        $activeCounts = User::query()
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->whereIn('organization_id', $admins->pluck('organization_id')->filter()->unique())
            ->selectRaw('organization_id, COUNT(*) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        return $admins->map(function (User $user) use ($activeCounts) {
            $activeInOrg = (int) ($activeCounts[$user->organization_id] ?? 0);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'deactivated_reason' => $user->deactivated_reason,
                'can_deactivate' => $user->is_active && $activeInOrg > 1,
                'organization_id' => $user->organization_id,
                'organization_name' => $user->organization?->name,
                'organization_status' => $user->organization?->accessStateLabel(),
                'organization_state' => $user->organization?->accessState(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function transformOrganization(Organization $organization, ?PlatformEvent $latestChange = null): array
    {
        $admin = $organization->users()
            ->where('role', UserRole::Administrator)
            ->orderBy('id')
            ->first();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'address' => $organization->address,
            'contact_number' => $organization->contact_number,
            'registered_at_label' => $organization->created_at?->format('M d, Y · g:i A'),
            'waiting_label' => $organization->created_at?->diffForHumans(),
            'is_active' => $organization->is_active,
            'access_state' => $organization->accessState(),
            'status_label' => $organization->accessStateLabel(),
            'admin_name' => $admin?->name,
            'admin_email' => $admin?->email,
            'boats_count' => $organization->boats_count ?? $organization->boats()->count(),
            'users_count' => $organization->users_count ?? $organization->users()->count(),
            'restore_requested' => $organization->hasPendingRestoreRequest(),
            'closure_requested' => $organization->hasPendingClosureRequest(),
            'last_change_action' => $latestChange?->action,
            'last_change_label' => $latestChange?->actionLabel(),
            'last_change_at_label' => $latestChange?->created_at?->format('M d, Y · g:i A'),
            'last_change_note' => $latestChange?->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformClosureRequest(Organization $organization): array
    {
        return [
            ...$this->transformOrganization($organization),
            'closure_requested_at_label' => $organization->closure_requested_at?->format('M d, Y · g:i A'),
            'closure_reason' => $organization->closure_reason,
            'closure_details' => $organization->closure_details,
            'closure_requested_by_name' => $organization->closureRequester?->name,
            'closure_requested_by_email' => $organization->closureRequester?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRestoreRequest(Organization $organization): array
    {
        return [
            ...$this->transformOrganization($organization),
            'restore_requested_at_label' => $organization->restore_requested_at?->format('M d, Y · g:i A'),
            'restore_request_reason' => $organization->restore_request_reason,
            'restore_request_details' => $organization->restore_request_details,
            'restore_requested_by_name' => $organization->restoreRequester?->name,
            'restore_requested_by_email' => $organization->restoreRequester?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformReactivationRequest(Organization $organization): array
    {
        return $this->transformRestoreRequest($organization);
    }
}
