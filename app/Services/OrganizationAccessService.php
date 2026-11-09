<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrganizationAccessService
{
    public function approve(Organization $organization, User $actor): void
    {
        abort_if($organization->isPlatformRecord(), 404);

        $organization->update([
            'is_active' => true,
            'approved_at' => $organization->approved_at ?? now(),
            'inactive_kind' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
            'suspended_by' => null,
        ]);

        $this->record($actor, $organization, 'approved');
    }

    public function suspend(Organization $organization, User $actor, ?string $reason = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);

        $organization->suspend($actor, $reason);
        $this->revokeAccess($organization);
        $this->record($actor, $organization, 'suspended', $reason);
    }

    public function deactivate(Organization $organization, User $actor, ?string $reason = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);

        $organization->deactivate($actor, $reason);
        $this->revokeAccess($organization);
        $this->record($actor, $organization, 'deactivated', $reason);
    }

    public function restore(Organization $organization, User $actor): void
    {
        abort_if($organization->isPlatformRecord(), 404);

        if ($organization->isPending()) {
            abort(422, 'Approve this organization before restoring access.');
        }

        $organization->restoreAccess();
        $this->record($actor, $organization, 'restored');
    }

    public function requestRestore(Organization $organization, User $admin, string $reason, ?string $details = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($organization->isAccessPaused(), 422, 'Only an inactive or suspended organization can request restore.');

        $details = $details !== null && trim($details) !== '' ? trim($details) : null;

        $organization->update([
            'restore_requested_at' => now(),
            'restore_request_reason' => trim($reason),
            'restore_request_details' => $details,
            'restore_requested_by' => $admin->id,
        ]);

        $this->record($admin, $organization, 'restore_requested', trim($reason).($details ? ' · '.$details : ''));
    }

    public function dismissRestoreRequest(Organization $organization, User $actor): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($organization->hasPendingRestoreRequest(), 422, 'There is no restore request to dismiss.');

        $action = $organization->isArchived() ? 'reactivation_rejected' : 'restore_request_dismissed';

        $organization->update([
            'restore_requested_at' => null,
            'restore_request_reason' => null,
            'restore_request_details' => null,
            'restore_requested_by' => null,
        ]);

        $this->record($actor, $organization, $action);
    }

    public function requestClosure(Organization $organization, User $admin, string $reason, ?string $details = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($admin->role === UserRole::Administrator, 403);
        abort_unless((int) $admin->organization_id === (int) $organization->id, 403);
        abort_unless($organization->is_active && ! $organization->isPending(), 422, 'Only an active organization can request closure.');
        abort_if($organization->hasPendingClosureRequest(), 422, 'A closure request is already waiting for Super Admin.');

        $details = $details !== null && trim($details) !== '' ? trim($details) : null;

        $organization->update([
            'closure_requested_at' => now(),
            'closure_reason' => trim($reason),
            'closure_details' => $details,
            'closure_requested_by' => $admin->id,
            'closure_rejected_at' => null,
            'closure_rejection_reason' => null,
        ]);

        $this->record($admin, $organization, 'closure_requested', trim($reason).($details ? ' · '.$details : ''));
    }

    public function rejectClosure(Organization $organization, User $actor, ?string $reason = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($organization->hasPendingClosureRequest(), 422, 'There is no closure request to reject.');

        $organization->update([
            'closure_requested_at' => null,
            'closure_reason' => null,
            'closure_details' => null,
            'closure_requested_by' => null,
            'closure_rejected_at' => now(),
            'closure_rejection_reason' => $reason ? trim($reason) : 'Closure request was rejected.',
        ]);

        $this->record($actor, $organization, 'closure_rejected', $reason);
    }

    public function approveClosure(Organization $organization, User $actor): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($organization->hasPendingClosureRequest(), 422, 'There is no closure request to approve.');

        $reason = $organization->closure_reason;

        $organization->pauseAccess($actor, $reason, 'archived');
        $this->revokeAccess($organization);
        $this->record($actor, $organization, 'archived', $reason);
    }

    public function requestReactivation(Organization $organization, User $admin, string $reason, ?string $details = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($admin->role === UserRole::Administrator, 403);
        abort_unless((int) $admin->organization_id === (int) $organization->id, 403);
        abort_unless($organization->isArchived(), 422, 'Only an archived organization can request reactivation.');
        abort_if($organization->hasPendingRestoreRequest(), 422, 'A reactivation request is already waiting for Super Admin.');

        $details = $details !== null && trim($details) !== '' ? trim($details) : null;

        $organization->update([
            'restore_requested_at' => now(),
            'restore_request_reason' => trim($reason),
            'restore_request_details' => $details,
            'restore_requested_by' => $admin->id,
        ]);

        $this->record($admin, $organization, 'reactivation_requested', trim($reason).($details ? ' · '.$details : ''));
    }

    public function reject(Organization $organization, User $actor, ?string $reason = null): void
    {
        abort_if($organization->isPlatformRecord(), 404);
        abort_unless($organization->isPending(), 422, 'Only pending registrations can be rejected.');
        abort_if($organization->boats()->exists(), 422, 'This organization already has boats.');

        $this->record($actor, $organization, 'rejected', $reason);
        $this->revokeAccess($organization);
        $organization->delete();
    }

    /**
     * @param  array{organization_name: string, organization_address: string, contact_number: string, name: string, email: string, password: string}  $data
     */
    public function onboard(User $actor, array $data): Organization
    {
        return DB::transaction(function () use ($actor, $data): Organization {
            $organization = Organization::query()->create([
                'tenant_id' => Organization::uniqueTenantId(),
                'name' => $data['organization_name'],
                'slug' => Organization::uniqueSlug($data['organization_name']),
                'address' => $data['organization_address'],
                'contact_number' => $data['contact_number'],
                'is_active' => true,
                'approved_at' => now(),
            ]);

            User::query()->create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Administrator,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $this->record($actor, $organization, 'onboarded');

            return $organization;
        });
    }

    public function setAdministratorActive(User $admin, User $actor, bool $active, ?string $reason = null): void
    {
        abort_unless($admin->role === UserRole::Administrator && $admin->organization_id, 404);

        $organization = $admin->organization ?? Organization::query()->find($admin->organization_id);
        abort_if(! $organization || $organization->isPlatformRecord(), 404);

        if (! $active) {
            $otherAdmins = User::query()
                ->where('organization_id', $admin->organization_id)
                ->where('id', '!=', $admin->id)
                ->where('role', UserRole::Administrator)
                ->where('is_active', true)
                ->count();

            if ($otherAdmins === 0) {
                throw ValidationException::withMessages([
                    'is_active' => 'This is the only active admin. Deactivate or suspend the organization instead.',
                ]);
            }

            $admin->update([
                'is_active' => false,
                'deactivated_reason' => $reason ? trim($reason) : null,
                'deactivated_at' => now(),
            ]);
            $this->revokeUserSessions($admin);
            $this->record($actor, $organization, 'admin_deactivated', trim($admin->email.($reason ? ' · '.$reason : '')));

            return;
        }

        $admin->update([
            'is_active' => true,
            'deactivated_reason' => null,
            'deactivated_at' => null,
        ]);
        $this->record($actor, $organization, 'admin_restored', $admin->email);
    }

    public function revokeUserSessions(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    public function revokeAccess(Organization $organization): void
    {
        $userIds = $organization->users()->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        User::query()->whereIn('id', $userIds)->each(function (User $user): void {
            $user->tokens()->delete();
        });

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        }
    }

    public function record(?User $actor, Organization $organization, string $action, ?string $note = null): void
    {
        PlatformEvent::query()->create([
            'actor_id' => $actor?->id,
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'action' => $action,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
    }
}
