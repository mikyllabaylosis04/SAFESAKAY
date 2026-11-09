<?php

use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('origin');
            $table->string('destination');
            $table->string('trip_type')->default('regular_public');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::table('regular_trips', function (Blueprint $table) {
            $table->string('direction')->nullable()->after('route');
            $table->unsignedTinyInteger('rotation_order')->default(1)->after('direction');
        });

        Organization::query()->each(function (Organization $organization): void {
            $organization->seedDefaultRoute();
        });

        RegularTrip::query()->withoutGlobalScopes()->each(function (RegularTrip $trip): void {
            $route = (string) $trip->route;

            if (! str_contains($route, '→') || str_contains($route, '↔')) {
                return;
            }

            [$origin, $destination] = array_map('trim', explode('→', $route, 2));
            $name = $origin.' ↔ '.$destination;

            $trip->forceFill([
                'route' => $name,
                'direction' => $origin.' → '.$destination,
            ])->save();

            if ($trip->organization_id) {
                OrganizationRoute::query()->firstOrCreate(
                    [
                        'organization_id' => $trip->organization_id,
                        'name' => $name,
                    ],
                    [
                        'origin' => $origin,
                        'destination' => $destination,
                        'trip_type' => 'regular_public',
                        'is_active' => true,
                        'sort_order' => 2,
                    ],
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->dropColumn(['direction', 'rotation_order']);
        });

        Schema::dropIfExists('organization_routes');
    }
};
