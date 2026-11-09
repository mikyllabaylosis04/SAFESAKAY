<?php

namespace App\Http\Middleware;

use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPlatformSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.lifetime' => PlatformSettings::sessionTimeout()]);

        return $next($request);
    }
}
