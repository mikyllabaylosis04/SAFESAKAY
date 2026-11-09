<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $organization = $user?->organization;

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'account' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'role_label' => $user?->role?->label(),
                'organization_name' => $organization?->name,
            ],
            'organization_settings' => $organization && ! $user?->isSuperAdmin()
                ? [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'status_label' => $organization->accessStateLabel(),
                    'can_request_closure' => $user?->isAdministrator()
                        && $organization->is_active
                        && ! $organization->hasPendingClosureRequest(),
                    'closure_status' => $organization->closureStatusLabel(),
                    'closure_reason' => $organization->closure_reason,
                    'closure_details' => $organization->closure_details,
                    'closure_requested_at_label' => $organization->closure_requested_at?->format('M d, Y · g:i A'),
                    'closure_rejection_reason' => $organization->closure_rejection_reason,
                    'closure_rejected_at_label' => $organization->closure_rejected_at?->format('M d, Y · g:i A'),
                    'closure_reasons' => Organization::closureReasons(),
                ]
                : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
