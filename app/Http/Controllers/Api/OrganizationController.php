<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $organizations = Organization::query()
            ->tenants()
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->orderByRaw("case when lower(name) like '%lakandula%' then 0 else 1 end")
            ->orderBy('name')
            ->get(['id', 'name', 'address']);

        return response()->json([
            'data' => $organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->displayName(),
                'address' => $organization->address,
            ])->values(),
        ]);
    }
}
