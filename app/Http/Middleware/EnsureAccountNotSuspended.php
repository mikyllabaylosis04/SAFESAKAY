<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $message = $user->operatorSuspensionDenialMessage();

            if ($message !== null) {
                return response()->json(['message' => $message], 403);
            }
        }

        return $next($request);
    }
}
