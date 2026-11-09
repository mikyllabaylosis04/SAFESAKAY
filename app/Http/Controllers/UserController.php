<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Models\Boat;
use App\Models\Operator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $organizationId = auth()->user()?->organization_id;

        $users = User::query()
            ->with(['organization', 'operatorProfiles.boat', 'operatorProfiles.violations'])
            ->where(function ($query) use ($organizationId): void {
                $query
                    ->where(function ($staff) use ($organizationId): void {
                        $staff->where('organization_id', $organizationId)
                            ->whereIn('role', [
                                UserRole::Administrator,
                                UserRole::User,
                                UserRole::Operator,
                                UserRole::Staff,
                            ]);
                    })
                    ->orWhere(function ($customers) use ($organizationId): void {
                        $customers->where('role', UserRole::Passenger)
                            ->where(function ($match) use ($organizationId): void {
                                $match->where('organization_id', $organizationId)
                                    ->orWhereHas('bookings');
                            });
                    });
            })
            ->orderBy('name')
            ->get();

        $organization = auth()->user()?->organization;

        return Inertia::render('users/Index', [
            'users' => $users->map(fn (User $user) => $this->transform($user)),
            'currentUserId' => auth()->id(),
            'roleOptions' => collect(UserRole::assignable())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ]),
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
            ] : null,
            'boats' => Boat::query()
                ->with('operator')
                ->orderBy('name')
                ->get()
                ->map(fn (Boat $boat) => [
                    'id' => $boat->id,
                    'name' => $boat->name,
                    'code' => $boat->code,
                    'label' => trim($boat->name.' ('.$boat->code.')'.($boat->operator?->full_name ? ' · '.$boat->operator->full_name : '')),
                    'assigned_user_id' => $boat->operator?->user_id,
                ]),
            'stats' => [
                'total' => $users->count(),
                'administrators' => $users->where('role', UserRole::Administrator)->count(),
                'operators' => $users->filter(
                    fn (User $user) => $user->role->canonical() === UserRole::Operator,
                )->count(),
                'customers' => $users->where('role', UserRole::Passenger)->count(),
                'inactive' => $users->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'organization_id' => $request->user()?->organization_id,
            'name' => $data['name'],
            'username' => User::uniqueUsernameFromEmail($data['email']),
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $request->boolean('is_active'),
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        $this->syncOperatorBoat($user, $data['boat_id'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User account created.']);

        return to_route('users.index');
    }

    public function update(StoreUserRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->organization_id === $request->user()?->organization_id, 404);

        $data = $request->validated();
        $isActive = $request->boolean('is_active');

        $this->guardAccountChange($user, $data['role'], $isActive);

        $payload = [
            'name' => $data['name'],
            'username' => $user->username ?: User::uniqueUsernameFromEmail($data['email'], $user->id),
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $isActive,
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);
        $this->syncOperatorBoat($user, $data['boat_id'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User account updated.']);

        return to_route('users.index');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManageUser($request->user(), $user), 404);

        $wantsActive = $request->boolean('is_active');

        $validated = $request->validate([
            'is_active' => ['required'],
            'reason' => $wantsActive
                ? ['nullable', 'string', 'max:180']
                : ['required', 'string', 'min:3', 'max:180'],
        ]);

        $this->guardAccountChange($user, $user->role->value, $wantsActive);

        if ($wantsActive) {
            $user->update([
                'is_active' => true,
                'deactivated_reason' => null,
                'deactivated_at' => null,
            ]);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Account restored.']);

            return to_route('users.index');
        }

        $user->update([
            'is_active' => false,
            'deactivated_reason' => trim((string) $validated['reason']),
            'deactivated_at' => now(),
        ]);

        $user->tokens()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account deactivated.']);

        return to_route('users.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(User $user): array
    {
        $profile = $user->operatorProfiles->first();
        $assignedBoat = $profile?->boat;
        $violations = $profile
            ? $profile->violations->sortBy('id')->values()
            : collect();

        if (
            $profile
            && $violations->count() >= \App\Services\OperatorNoShowViolationService::MAX_STRIKES
            && $profile->account_suspension_ends_at === null
        ) {
            $profile->suspendAccount('Multiple violations');
        }

        $profile?->refreshAccountSuspension();
        $suspended = $profile?->isAccountSuspended() ?? false;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role->canonical()->value,
            'role_label' => $user->role->canonical()->label(),
            'role_description' => $user->role->canonical()->description(),
            'organization_name' => $user->organization?->name,
            'boat_id' => $assignedBoat?->id,
            'boat_name' => $assignedBoat?->name,
            'boat_label' => $assignedBoat
                ? $assignedBoat->name.' ('.$assignedBoat->code.')'
                : null,
            'violation_count' => $profile ? $violations->count() : null,
            'violations' => $violations->map(fn ($violation) => [
                'id' => $violation->id,
                'strike_label' => $violation->strike_number.'/'.\App\Services\OperatorNoShowViolationService::MAX_STRIKES,
                'incident_date_label' => $violation->incident_date?->format('M j, Y'),
                'booking_code' => $violation->booking_code,
                'reason' => $violation->reason,
                'recorded_at_label' => $violation->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A'),
            ])->all(),
            'is_suspended' => $suspended,
            'suspension' => $suspended ? [
                'reason' => $profile->account_suspension_reason ?: 'Multiple violations',
                'started_at_label' => $profile->account_suspended_at?->timezone('Asia/Manila')->format('M j, Y'),
                'ends_at_label' => $profile->account_suspension_ends_at?->timezone('Asia/Manila')->format('M j, Y'),
                'remaining_label' => $profile->suspensionRemainingLabel(),
            ] : null,
            'is_active' => $user->is_active,
            'deactivated_reason' => $user->deactivated_reason,
            'deactivated_at_label' => $user->deactivated_at?->format('M d, Y · g:i A'),
            'status_label' => $suspended ? 'Suspended' : ($user->is_active ? 'Active' : 'Inactive'),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'created_at_label' => $user->created_at?->format('M d, Y'),
            'last_active_label' => $this->lastActiveLabel($user),
            'is_current' => $user->id === auth()->id(),
        ];
    }

    private function lastActiveLabel(User $user): string
    {
        $sessionActivity = DB::table('sessions')
            ->where('user_id', $user->id)
            ->max('last_activity');

        $tokenActivity = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->max('last_used_at');

        $timestamps = array_filter([
            $sessionActivity ? (int) $sessionActivity : null,
            $tokenActivity ? Carbon::parse($tokenActivity)->getTimestamp() : null,
        ]);

        if ($timestamps === []) {
            return 'Never signed in';
        }

        return Carbon::createFromTimestamp((int) max($timestamps))->diffForHumans();
    }

    private function syncOperatorBoat(User $user, mixed $boatId): void
    {
        Operator::query()->where('user_id', $user->id)->update(['user_id' => null]);

        if ($user->role->canonical() !== UserRole::Operator) {
            return;
        }

        if ($boatId === null || $boatId === '') {
            return;
        }

        $operator = Operator::query()
            ->where('boat_id', $boatId)
            ->whereHas('boat', function ($query) use ($user): void {
                $query->where('organization_id', $user->organization_id);
            })
            ->first();

        $operator?->update(['user_id' => $user->id]);
    }

    private function canManageUser(?User $actor, User $user): bool
    {
        if (! $actor) {
            return false;
        }

        if ($user->organization_id === $actor->organization_id) {
            return true;
        }

        return $user->role === UserRole::Passenger
            && $user->bookings()
                ->where('organization_id', $actor->organization_id)
                ->exists();
    }

    private function guardAccountChange(User $user, string $role, bool $isActive): void
    {
        if ($user->id === auth()->id() && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account.',
            ]);
        }

        $wasAdmin = $user->role === UserRole::Administrator && $user->is_active;
        $staysAdmin = $role === UserRole::Administrator->value && $isActive;

        if ($wasAdmin && ! $staysAdmin) {
            $otherAdmins = User::query()
                ->where('organization_id', $user->organization_id)
                ->where('id', '!=', $user->id)
                ->where('role', UserRole::Administrator)
                ->where('is_active', true)
                ->count();

            if ($otherAdmins === 0) {
                throw ValidationException::withMessages([
                    'role' => 'Keep at least one active administrator.',
                ]);
            }
        }
    }
}
