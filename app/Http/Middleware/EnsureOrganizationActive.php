<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationActive
{
    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin() || ! $user->organization) {
            return $next($request);
        }

        if ($user->organization->providesOperations()) {
            return $next($request);
        }

        return response()->json($user->organization->inactiveOperationsPayload(), 403);
    }
}
