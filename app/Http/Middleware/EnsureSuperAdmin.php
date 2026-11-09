<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isSuperAdmin()) {
            return redirect()->route($user?->homeRouteName() ?? 'dashboard');
        }

        return $next($request);
    }
}
