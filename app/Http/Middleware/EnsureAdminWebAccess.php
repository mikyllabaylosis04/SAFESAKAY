<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminWebAccess
{
    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isSuperAdmin() && $user->organization?->isArchived()) {
            if (! $request->routeIs([
                'organization.archived',
                'organization.reactivate',
                'logout',
                'superadmin.logout',
            ])) {
                return redirect()->route('organization.archived');
            }
        }

        if ($user && $user->organizationBlocksLogin()) {
            $message = $user->organizationAccessDenialMessage();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    Fortify::username() => $message,
                ]);
        }

        if ($user && ! $user->role->canAccessAdminWeb()) {
            $message = $user->role->adminWebDenialMessage();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    Fortify::username() => $message,
                ]);
        }

        return $next($request);
    }
}
