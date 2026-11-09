<?php

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$file = __DIR__.'/storage/inertia-devtools/01M3X2M70NDFTHRH9YW97BDHD8.json';
$j = json_decode(file_get_contents($file), true);
$props = $j['propValues'] ?? $j['http']['responseBody']['value']['props'] ?? null;

if (! is_array($props)) {
    fwrite(STDERR, "No props found\n");
    exit(1);
}

$authEmail = $props['auth']['user']['email'] ?? 'lakandula@safesakay.com';
$authName = $props['auth']['user']['name'] ?? 'Lakandula Admin';
$trips = $props['trips'] ?? [];
$boats = $props['boats'] ?? [];
$routes = $props['organizationRoutes'] ?? [];
$ports = $props['ports'] ?? ['origin' => 'Lakandula', 'destination' => 'Placer'];

echo 'auth='.$authEmail.PHP_EOL;
echo 'boats='.count($boats).PHP_EOL;
echo 'trips='.count($trips).PHP_EOL;
echo 'routes='.count($routes).PHP_EOL;

DB::transaction(function () use ($authEmail, $authName, $boats, $trips, $routes, $ports) {
    $org = Organization::query()->where('name', 'like', '%Lakandula%')->first();

    if (! $org) {
        $org = Organization::query()->create([
            'name' => 'Barangay Lakandula',
            'address' => 'Barangay Lakandula',
            'contact_number' => '09171234567',
        ]);
    }

    $user = User::query()->where('email', $authEmail)->first();
    if (! $user) {
        $user = User::query()->create([
            'organization_id' => $org->id,
            'name' => $authName,
            'email' => $authEmail,
            'username' => 'lakandula',
            'password' => 'password',
            'role' => UserRole::Administrator,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    } else {
        $user->update([
            'organization_id' => $org->id,
            'name' => $authName,
            'password' => Hash::make('password'),
            'role' => UserRole::Administrator,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // Routes
    if ($routes === []) {
        $routes = [[
            'name' => ($ports['origin'] ?? 'Lakandula').' ↔ '.($ports['destination'] ?? 'Placer'),
            'origin' => $ports['origin'] ?? 'Lakandula',
            'destination' => $ports['destination'] ?? 'Placer',
            'trip_type' => 'regular_public',
            'is_active' => true,
            'sort_order' => 1,
        ]];
    }

    foreach ($routes as $index => $route) {
        OrganizationRoute::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'name' => $route['name'] ?? OrganizationRoute::makeName($route['origin'], $route['destination']),
            ],
            [
                'origin' => $route['origin'] ?? $ports['origin'] ?? 'Lakandula',
                'destination' => $route['destination'] ?? $ports['destination'] ?? 'Placer',
                'trip_type' => $route['trip_type'] ?? 'regular_public',
                'is_active' => $route['is_active'] ?? true,
                'sort_order' => $route['sort_order'] ?? ($index + 1),
            ],
        );
    }

    // Clear existing org boats/trips so we can reinsert with original IDs where possible
    RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $org->id)->forceDelete();
    Boat::query()->withoutGlobalScope('organization')->where('organization_id', $org->id)->forceDelete();

    $boatIdMap = [];

    foreach ($boats as $boat) {
        $oldId = (int) $boat['id'];
        $status = BoatStatus::tryFrom((string) ($boat['status'] ?? 'available')) ?? BoatStatus::Available;
        $serviceType = (string) ($boat['service_type'] ?? 'both');
        $availability = match ($serviceType) {
            'regular_trip' => [BoatService::RegularTrip->value],
            'private_booking' => [BoatService::PrivateBooking->value],
            default => BoatService::values(),
        };

        $pictureUrl = (string) ($boat['picture_url'] ?? '');
        $picturePath = null;
        if ($pictureUrl !== '' && preg_match('#/storage/(.+)$#', $pictureUrl, $m)) {
            $picturePath = $m[1];
        }

        // Insert preserving id when possible
        DB::table('boats')->insert([
            'id' => $oldId,
            'organization_id' => $org->id,
            'name' => $boat['name'],
            'code' => $boat['code'] ?? ('B-'.$oldId),
            'capacity' => (int) ($boat['capacity'] ?? 20),
            'type' => 'Pump Boat',
            'license_no' => 'LIC-'.str_pad((string) $oldId, 5, '0', STR_PAD_LEFT),
            'registration_date' => now()->subYear()->toDateString(),
            'status' => $status->value,
            'registration_status' => BoatRegistrationStatus::Registered->value,
            'service_type' => $serviceType === 'both' ? BoatService::RegularTrip->value : $serviceType,
            'service_availability' => json_encode($availability),
            'picture_path' => $picturePath,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $boatIdMap[$oldId] = $oldId;
        echo "boat restored id={$oldId} name={$boat['name']}".PHP_EOL;
    }

    // Fix autoincrement
    $maxBoat = (int) DB::table('boats')->max('id');
    DB::statement('ALTER TABLE boats AUTO_INCREMENT = '.($maxBoat + 1));

    foreach ($trips as $trip) {
        $oldId = (int) $trip['id'];
        $boatId = (int) ($trip['boat']['id'] ?? 0);
        if (! isset($boatIdMap[$boatId])) {
            echo "skip trip {$oldId}, missing boat {$boatId}".PHP_EOL;
            continue;
        }

        $status = RegularTripStatus::tryFrom((string) ($trip['status'] ?? 'scheduled')) ?? RegularTripStatus::Scheduled;
        $mode = RegularTripScheduleMode::tryFrom((string) ($trip['schedule_mode'] ?? 'timed')) ?? RegularTripScheduleMode::Timed;

        $departureTime = null;
        if (($trip['has_departure_time'] ?? true) && ! empty($trip['departure_time'])) {
            $departureTime = $trip['departure_time'];
        } elseif (! empty($trip['departure_time'])) {
            $departureTime = $trip['departure_time'];
        } else {
            // Column is NOT NULL; rush / no-set-time slots use midnight placeholder.
            $departureTime = '00:00:00';
        }

        DB::table('regular_trips')->insert([
            'id' => $oldId,
            'organization_id' => $org->id,
            'boat_id' => $boatId,
            'route' => $trip['route'],
            'direction' => $trip['direction'],
            'rotation_order' => (int) ($trip['rotation_order'] ?? 1),
            'schedule_mode' => $mode->value,
            'trip_date' => $trip['trip_date'],
            'departure_time' => $departureTime,
            'passenger_count' => (int) ($trip['passenger_count'] ?? 0),
            'status' => $status->value,
            'remarks' => $trip['remarks'] ?? null,
            'is_auto_generated' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $maxTrip = (int) DB::table('regular_trips')->max('id');
    DB::statement('ALTER TABLE regular_trips AUTO_INCREMENT = '.($maxTrip + 1));

    echo 'org_id='.$org->id.PHP_EOL;
    echo 'user_id='.$user->id.PHP_EOL;
    echo 'boats_now='.Boat::query()->withoutGlobalScope('organization')->where('organization_id', $org->id)->count().PHP_EOL;
    echo 'trips_now='.RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $org->id)->count().PHP_EOL;
});

echo "DONE password=password email={$authEmail}".PHP_EOL;
