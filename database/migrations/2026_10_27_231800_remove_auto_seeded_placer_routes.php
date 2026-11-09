<?php

use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_routes')) {
            return;
        }

        OrganizationRoute::query()
            ->with('organization')
            ->where('destination', 'Placer')
            ->whereNull('regular_fare')
            ->whereNull('rental_price')
            ->get()
            ->each(function (OrganizationRoute $route): void {
                $organization = $route->organization;
                if (! $organization) {
                    return;
                }

                $haystack = mb_strtolower($organization->name.' '.$organization->address.' '.$organization->slug);
                if (str_contains($haystack, 'lakandula')) {
                    return;
                }

                $originFromName = trim((string) preg_replace('/^barangay\s+/i', '', (string) $organization->name));
                $originMatch = strcasecmp((string) $route->origin, $originFromName) === 0
                    || strcasecmp((string) $route->origin, (string) $organization->name) === 0;
                if (! $originMatch) {
                    return;
                }

                $hasTrips = Schema::hasTable('regular_trips')
                    && RegularTrip::query()
                        ->where('organization_id', $organization->id)
                        ->where(function ($query) use ($route): void {
                            $query->where('route', $route->name)
                                ->orWhere('route', $route->origin.' -- '.$route->destination)
                                ->orWhere('route', $route->origin.' → '.$route->destination);
                        })
                        ->exists();
                if ($hasTrips) {
                    return;
                }

                if (Schema::hasTable('boat_organization_route')) {
                    DB::table('boat_organization_route')->where('organization_route_id', $route->id)->delete();
                }

                $route->delete();
            });
    }

    public function down(): void
    {
        // Cleanup of auto-seeded empty Placer routes is not reversed.
    }
};
