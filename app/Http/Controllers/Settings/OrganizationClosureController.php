<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrganizationClosureController extends Controller
{
    public function store(Request $request, OrganizationAccessService $access): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Administrator, 403);

        $organization = $user->organization;
        abort_unless($organization && ! $organization->isPlatformRecord(), 404);

        $otherReason = 'Other reason';

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(Organization::closureReasons())],
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

        $access->requestClosure($organization, $user, $reason, $data['details'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Your closure request was sent to Super Admin.',
        ]);

        return back();
    }
}
