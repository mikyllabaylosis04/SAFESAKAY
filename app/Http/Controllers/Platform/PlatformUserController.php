<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PlatformUserController extends Controller
{
    public function index(): Response
    {
        // Passenger and operator accounts only.
        $users = User::query()
            ->whereIn('role', ['passenger', 'operator'])
            ->orderByDesc('id')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'organization_id',
                'created_at',
            ]);

        // Get organization names for the listed users.
        $organizationNames = DB::table('organizations')
            ->whereIn(
                'id',
                $users->pluck('organization_id')->filter()->unique()
            )
            ->pluck('name', 'id');

        return Inertia::render('platform/Users', [
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => (bool) $user->is_active,
                'organization_name' => $organizationNames->get(
                    $user->organization_id
                ),
                'created_at' => $user->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
