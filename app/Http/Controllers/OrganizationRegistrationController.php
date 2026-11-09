<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreOrganizationRegistrationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('organization/Register', [
            'accountCreated' => (bool) session('accountCreated'),
            'takenLocations' => Organization::query()
                ->where('slug', '!=', 'safesakay')
                ->get(['name', 'address'])
                ->map(fn (Organization $organization) => [
                    'name' => $organization->name,
                    'address' => $organization->address,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreOrganizationRegistrationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $organization = Organization::query()->create([
                'tenant_id' => Organization::uniqueTenantId(),
                'name' => $data['organization_name'],
                'slug' => Organization::uniqueSlug($data['organization_name']),
                'address' => $data['organization_address'],
                'contact_number' => $data['contact_number'],
                'is_active' => false,
                'approved_at' => null,
            ]);

            User::query()->create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Administrator,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        });

        return to_route('register')->with('accountCreated', true);
    }

    public function locations(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', '');
        $code = strtolower((string) $request->query('code', ''));
        $islandGroups = ['luzon', 'visayas', 'mindanao'];
        $isIslandGroup = in_array($code, $islandGroups, true);

        if ($code !== '' && ! $isIslandGroup && ! preg_match('/^\d{9,10}$/', $code)) {
            abort(422, 'Invalid location code.');
        }

        $url = match ($type) {
            'island-groups' => 'https://psgc.gitlab.io/api/island-groups/',
            'island-provinces' => "https://psgc.gitlab.io/api/island-groups/{$code}/provinces/",
            'regions' => 'https://psgc.gitlab.io/api/regions/',
            'provinces' => "https://psgc.gitlab.io/api/regions/{$code}/provinces/",
            'districts' => "https://psgc.gitlab.io/api/regions/{$code}/districts/",
            'cities' => "https://psgc.gitlab.io/api/provinces/{$code}/cities-municipalities/",
            'district-cities' => "https://psgc.gitlab.io/api/districts/{$code}/cities-municipalities/",
            'region-cities' => "https://psgc.gitlab.io/api/regions/{$code}/cities-municipalities/",
            'barangays' => "https://psgc.gitlab.io/api/cities-municipalities/{$code}/barangays/",
            default => null,
        };

        if ($url === null || (
            ! in_array($type, ['regions', 'island-groups'], true) && $code === ''
        )) {
            abort(422, 'Invalid location request.');
        }

        try {
            $items = Cache::remember(
                "psgc:{$type}:{$code}",
                now()->addDay(),
                function () use ($url): array {
                    $response = Http::acceptJson()->timeout(8)->get($url);

                    if (! $response->successful()) {
                        throw new \RuntimeException('Location service unavailable.');
                    }

                    $payload = $response->json();

                    return is_array($payload) ? $payload : [];
                },
            );
        } catch (\Throwable) {
            $items = [];
        }

        return response()->json($items);
    }
}
