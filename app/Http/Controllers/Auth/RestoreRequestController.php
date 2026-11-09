<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RestoreRequestController extends Controller
{
    public function store(Request $request, OrganizationAccessService $access): RedirectResponse
    {
        $otherReason = 'Other reason';

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', Rule::in(Organization::reactivationReasons())],
            'specify_reason' => [
                Rule::requiredIf($request->input('reason') === $otherReason),
                'nullable',
                'string',
                'min:3',
                'max:160',
            ],
            'details' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $reason = $data['reason'] === $otherReason
            ? $otherReason.': '.trim((string) $data['specify_reason'])
            : $data['reason'];

        $login = trim($data['email']);

        $user = User::query()
            ->with('organization')
            ->where(function ($query) use ($login): void {
                $query->where('email', $login)->orWhere('username', $login);
            })
            ->first();

        $organization = $user?->organization;

        if (
            $user
            && $user->role === UserRole::Administrator
            && $organization
            && ! $organization->isPlatformRecord()
            && $organization->isAccessPaused()
        ) {
            $access->requestRestore($organization, $user, $reason, $data['details']);
        }

        return back()->with(
            'status',
            'If this organization is deactivated, Super Admin will review your restore request.',
        );
    }
}
