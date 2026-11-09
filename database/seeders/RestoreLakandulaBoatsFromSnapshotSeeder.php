<?php

namespace Database\Seeders;

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RestoreLakandulaBoatsFromSnapshotSeeder extends Seeder
{
    public const BOATS_SNAPSHOT = 'inertia-devtools/01M5WBCB6D5PGGZNV51PY5TYBD.json';

    public const TRIPS_SNAPSHOT = 'inertia-devtools/01M5VRYNM1B99D8J5DFDGJRVTP.json';

    public function run(): void
    {
        $this->call(EnsureLakandula707AdminSeeder::class);

        $organization = Organization::query()
            ->where('name', 'BARANGAY LAKANDULA')
            ->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $boatsProps = $this->loadProps(self::BOATS_SNAPSHOT);
        $tripsProps = $this->loadProps(self::TRIPS_SNAPSHOT);

        $boats = $boatsProps['boats'] ?? [];
        $trips = $tripsProps['trips'] ?? [];
        $routes = $tripsProps['organizationRoutes'] ?? [];
        $ports = $tripsProps['ports'] ?? ['origin' => 'Lakandula', 'destination' => 'Placer'];

        if ($boats === []) {
            $this->command?->error('No boats in snapshot.');

            return;
        }

        $tripCount = 0;
        $boatCount = 0;

        DB::transaction(function () use ($organization, $boats, $trips, $routes, $ports, &$tripCount, &$boatCount): void {
            $orgId = $organization->id;
            $boatIds = Boat::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->pluck('id');

            RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->forceDelete();
            BoatBooking::query()->where('organization_id', $orgId)->delete();
            Operator::query()->whereIn('boat_id', $boatIds)->delete();
            Boat::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->forceDelete();

            $this->restoreRoutes($orgId, $routes, $ports);

            $boatIdMap = [];
            foreach ($boats as $boat) {
                $oldId = (int) $boat['id'];
                $boatIdMap[$oldId] = $this->insertBoat($orgId, $boat);
                $this->insertOperator($boatIdMap[$oldId], $boat['operator'] ?? null);
            }

            $boatCount = count($boatIdMap);

            foreach ($trips as $trip) {
                $boatId = (int) ($trip['boat']['id'] ?? 0);
                if (! isset($boatIdMap[$boatId])) {
                    continue;
                }

                $this->insertTrip($orgId, $boatId, $trip);
                $tripCount++;
            }
        });

        $this->fixAutoIncrement('boats');
        $this->fixAutoIncrement('regular_trips');

        $this->command?->info("Lakandula boats restored: {$boatCount} boats, {$tripCount} schedule trips.");

        $this->call([
            RestoreLakandulaUserHistorySeeder::class,
            RestoreLakandulaOperatorGcashSeeder::class,
            RestoreLakandulaRoutesFromSnapshotSeeder::class,
            RestoreLakandulaBoatRentalDestinationsSeeder::class,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProps(string $relativePath): array
    {
        $path = storage_path($relativePath);

        if (! File::isFile($path)) {
            $this->command?->error("Snapshot missing: {$path}");

            return [];
        }

        $payload = json_decode(File::get($path), true);

        return $payload['propValues']
            ?? $payload['http']['responseBody']['value']['props']
            ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $routes
     * @param  array<string, string>  $ports
     */
    private function restoreRoutes(int $orgId, array $routes, array $ports): void
    {
        OrganizationRoute::query()->where('organization_id', $orgId)->delete();

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
            OrganizationRoute::query()->create([
                'organization_id' => $orgId,
                'name' => $route['name'] ?? OrganizationRoute::makeName($route['origin'], $route['destination']),
                'origin' => $route['origin'] ?? $ports['origin'] ?? 'Lakandula',
                'destination' => $route['destination'] ?? $ports['destination'] ?? 'Placer',
                'trip_type' => $route['trip_type'] ?? 'regular_public',
                'regular_fare' => $route['regular_fare'] ?? null,
                'student_discount_percent' => $route['student_discount_percent'] ?? 20,
                'senior_discount_percent' => $route['senior_discount_percent'] ?? 20,
                'rental_price' => $route['rental_price'] ?? null,
                'down_payment_percent' => $route['down_payment_percent'] ?? null,
                'is_active' => $route['is_active'] ?? true,
                'sort_order' => $route['sort_order'] ?? ($index + 1),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $boat
     */
    private function insertBoat(int $orgId, array $boat): int
    {
        $oldId = (int) $boat['id'];
        $status = BoatStatus::tryFrom((string) ($boat['status'] ?? 'available')) ?? BoatStatus::Available;
        $serviceType = (string) ($boat['service_type'] ?? 'both');
        $availability = $boat['service_availability'] ?? match ($serviceType) {
            'regular_trip' => [BoatService::RegularTrip->value],
            'private_booking' => [BoatService::PrivateBooking->value],
            default => BoatService::values(),
        };

        $picturePath = $this->storagePathFromUrl((string) ($boat['picture_url'] ?? ''));
        $registrationStatus = BoatRegistrationStatus::tryFrom((string) ($boat['registration_status'] ?? 'registered'))
            ?? BoatRegistrationStatus::Registered;

        DB::table('boats')->insert([
            'id' => $oldId,
            'organization_id' => $orgId,
            'name' => $boat['name'],
            'code' => $boat['code'] ?? ('B-'.$oldId),
            'capacity' => (int) ($boat['capacity'] ?? 20),
            'type' => $boat['type'] ?? 'Pump Boat',
            'license_no' => $boat['license_no'] ?? ('LIC-'.str_pad((string) $oldId, 5, '0', STR_PAD_LEFT)),
            'registration_date' => $boat['registration_date'] ?? now()->subYear()->toDateString(),
            'status' => $status->value,
            'registration_status' => $registrationStatus->value,
            'service_type' => $serviceType === 'both' ? BoatService::RegularTrip->value : $serviceType,
            'service_availability' => json_encode($availability),
            'safety_information' => $boat['safety_information'] ?? null,
            'safety_equipment' => isset($boat['safety_equipment']) ? json_encode($boat['safety_equipment']) : null,
            'life_jacket_quantity' => $boat['life_jacket_quantity'] ?? null,
            'picture_path' => $picturePath,
            'is_active' => (bool) ($boat['is_active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $oldId;
    }

    /**
     * @param  array<string, mixed>|null  $operator
     */
    private function insertOperator(int $boatId, ?array $operator): void
    {
        if ($operator === null || ($operator['full_name'] ?? '') === '') {
            return;
        }

        Operator::query()->create([
            'boat_id' => $boatId,
            'user_id' => null,
            'full_name' => $operator['full_name'],
            'first_name' => $operator['first_name'] ?? null,
            'middle_name' => $operator['middle_name'] ?? null,
            'last_name' => $operator['last_name'] ?? null,
            'age' => $operator['age'] ?? null,
            'gender' => $operator['gender'] ?? null,
            'contact_number' => $operator['contact_number'] ?? '09000000000',
            'address' => $operator['address'] ?? null,
            'experience_years' => $operator['experience_years'] ?? null,
            'emergency_contact_name' => $operator['emergency_contact_name'] ?? null,
            'emergency_contact_number' => $operator['emergency_contact_number'] ?? null,
            'picture_path' => $this->storagePathFromUrl((string) ($operator['picture_url'] ?? '')),
            'admin_use_only' => (bool) ($operator['admin_use_only'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $trip
     */
    private function insertTrip(int $orgId, int $boatId, array $trip): void
    {
        $oldId = (int) $trip['id'];
        $status = RegularTripStatus::tryFrom((string) ($trip['status'] ?? 'scheduled')) ?? RegularTripStatus::Scheduled;
        $mode = RegularTripScheduleMode::tryFrom((string) ($trip['schedule_mode'] ?? 'timed')) ?? RegularTripScheduleMode::Timed;

        $departureTime = '00:00:00';
        if (! empty($trip['departure_time'])) {
            $departureTime = $trip['departure_time'];
        }

        DB::table('regular_trips')->insert([
            'id' => $oldId,
            'organization_id' => $orgId,
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
            'is_auto_generated' => (bool) ($trip['is_auto_generated'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storagePathFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('#/storage/(.+)$#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function fixAutoIncrement(string $table): void
    {
        $max = (int) DB::table($table)->max('id');
        if ($max > 0) {
            DB::statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.($max + 1));
        }
    }
}
