<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Services\OrganizationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationArchiveController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $organization = $request->user()?->organization;

        if (! $organization?->isArchived()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('organization/Archived', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'status_label' => $organization->accessStateLabel(),
                'archived_at_label' => $organization->suspended_at?->format('M d, Y · g:i A'),
                'archived_reason' => $organization->suspended_reason,
                'reactivation_requested' => $organization->hasPendingRestoreRequest(),
                'reactivation_reason' => $organization->restore_request_reason,
                'reactivation_details' => $organization->restore_request_details,
                'reactivation_requested_at_label' => $organization->restore_requested_at?->format('M d, Y · g:i A'),
                'reactivation_reasons' => Organization::reactivationReasons(),
            ],
        ]);
    }

    public function reactivate(Request $request, OrganizationAccessService $access): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Administrator, 403);

        $organization = $user->organization;
        abort_unless($organization?->isArchived(), 404);

        $otherReason = 'Other reason';

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(Organization::reactivationReasons())],
            'specify_reason' => [
                Rule::requiredIf($request->input('reason') === $otherReason),
                'nullable',
                'string',
                'min:3',
                'max:160',
            ],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = $data['reason'] === $otherReason
            ? $otherReason.': '.trim((string) $data['specify_reason'])
            : $data['reason'];

        $access->requestReactivation($organization, $user, $reason, $data['details'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Your reactivation request was sent to Super Admin.',
        ]);

        return back();
    }
}
