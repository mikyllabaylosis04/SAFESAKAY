<?php

namespace Database\Seeders;

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use Database\Seeders\Concerns\LoadsLakandulaBookingsFromSnapshots;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RestoreBarangayTaftaFromSnapshotSeeder extends Seeder
{
    use LoadsLakandulaBookingsFromSnapshots;

    public const ORG_NAME = 'BARANGAY TAFTA';

    private const ORG_SNAPSHOT = 'inertia-devtools/01M5VZB2B2E2MTMZV8W0SDNM4P.json';

    private const BOATS_SNAPSHOT = 'inertia-devtools/01M5VZ5NWCBJH5B5W52KH2Q7QZ.json';

    private const ROUTES_SNAPSHOT = 'inertia-devtools/01M5VZ69VYSGFF4TTYKBV5027K.json';

    private const TRIPS_SNAPSHOT = 'inertia-devtools/01M5W06M1TBV8PRF2D8JGE7F1X.json';

    private const ADMIN_EMAIL = 'surigaocity@gmail.com';

    public function run(): void
    {
        $orgProps = $this->loadSnapshotProps(self::ORG_SNAPSHOT);
        $orgRow = $orgProps['organization'] ?? [];

        $organization = Organization::query()->firstOrCreate(
            ['name' => self::ORG_NAME],
            [
                'tenant_id' => $orgRow['tenant_id'] ?? 'SS-XHTIBAED',
                'address' => 'Taft (Pob.), City of Surigao, Surigao Del Norte',
                'contact_number' => '09633040564',
                'is_active' => true,
                'approved_at' => Carbon::parse('2026-10-26'),
            ],
        );

        $organization->update([
            'tenant_id' => $orgRow['tenant_id'] ?? $organization->tenant_id,
            'address' => $orgRow['address'] ?? $organization->address,
            'contact_number' => $orgRow['contact_number'] ?? $organization->contact_number,
            'is_active' => true,
            'approved_at' => $organization->approved_at ?? Carbon::parse('2026-10-26'),
            'suspended_at' => null,
            'suspended_reason' => null,
            'inactive_kind' => null,
            'restore_requested_at' => null,
            'closure_requested_at' => null,
            'closure_rejected_at' => null,
        ]);

        User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'organization_id' => $organization->id,
                'name' => 'Surigao City',
                'username' => User::uniqueUsernameFromEmail(self::ADMIN_EMAIL),
                'password' => Hash::make('surigaocity'),
                'role' => UserRole::Administrator,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $orgId = $organization->id;
        $boats = $this->loadSnapshotProps(self::BOATS_SNAPSHOT)['boats'] ?? [];
        $routes = $this->loadSnapshotProps(self::ROUTES_SNAPSHOT)['routes'] ?? [];
        $trips = $this->loadSnapshotProps(self::TRIPS_SNAPSHOT)['trips'] ?? [];

        DB::transaction(function () use ($orgId, $boats, $routes, $trips): void {
            $this->restoreRoutes($orgId, $routes);
            RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->forceDelete();
            Boat::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->forceDelete();

            foreach ($boats as $boat) {
                if (! is_array($boat)) {
                    continue;
                }

                $boatId = $this->insertBoat($orgId, $boat);
                $this->insertOperator($boatId, $boat['operator'] ?? null);

                foreach ($trips as $trip) {
                    if (! is_array($trip)) {
                        continue;
                    }

                    $tripBoatId = (int) ($trip['boat']['id'] ?? 0);
                    if ($tripBoatId !== (int) ($boat['id'] ?? 0)) {
                        continue;
                    }

                    $this->insertTrip($orgId, $boatId, $trip);
                }
            }
        });

        $this->fixAutoIncrement('boats');
        $this->fixAutoIncrement('regular_trips');

        $boatCount = Boat::withoutGlobalScope('organization')->where('organization_id', $orgId)->count();
        $tripCount = RegularTrip::withoutGlobalScope('organization')->where('organization_id', $orgId)->count();

        $this->command?->info(self::ORG_NAME." restored: {$boatCount} boat(s), {$tripCount} trips. Admin: ".self::ADMIN_EMAIL.' / surigaocity');
    }

    /**
     * @param  list<array<string, mixed>>  $routes
     */
    private function restoreRoutes(int $orgId, array $routes): void
    {
        OrganizationRoute::query()->where('organization_id', $orgId)->delete();

        foreach ($routes as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            OrganizationRoute::query()->create([
                'organization_id' => $orgId,
                'name' => $route['name'],
                'origin' => $route['origin'],
                'destination' => $route['destination'],
                'trip_type' => $route['trip_type'] ?? 'regular_public',
                'is_active' => (bool) ($route['is_active'] ?? true),
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $boat
     */
    private function insertBoat(int $orgId, array $boat): int
    {
        $oldId = (int) $boat['id'];
        $status = BoatStatus::tryFrom((string) ($boat['status'] ?? 'on_duty')) ?? BoatStatus::OnDuty;
        $registrationStatus = BoatRegistrationStatus::tryFrom((string) ($boat['registration_status'] ?? 'registered'))
            ?? BoatRegistrationStatus::Registered;

        DB::table('boats')->where('id', $oldId)->delete();

        DB::table('boats')->insert([
            'id' => $oldId,
            'organization_id' => $orgId,
            'name' => $boat['name'],
            'code' => $boat['code'] ?? 'PB-001',
            'capacity' => (int) ($boat['capacity'] ?? 10),
            'type' => $boat['type'] ?? 'Pump Boat',
            'license_no' => $boat['license_no'] ?? 'LIC-TAFTA',
            'registration_date' => $boat['registration_date'] ?? '2026-10-26',
            'status' => $status->value,
            'registration_status' => $registrationStatus->value,
            'service_type' => BoatService::RegularTrip->value,
            'service_availability' => json_encode($boat['service_availability'] ?? BoatService::values()),
            'safety_information' => $boat['safety_information'] ?? null,
            'safety_equipment' => isset($boat['safety_equipment']) ? json_encode($boat['safety_equipment']) : null,
            'life_jacket_quantity' => $boat['life_jacket_quantity'] ?? null,
            'picture_path' => $this->storagePathFromUrl((string) ($boat['picture_url'] ?? '')),
            'is_active' => true,
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

        Operator::query()->where('boat_id', $boatId)->delete();

        Operator::query()->create([
            'boat_id' => $boatId,
            'user_id' => null,
            'full_name' => $operator['full_name'],
            'first_name' => $operator['first_name'] ?? null,
            'middle_name' => $operator['middle_name'] ?? null,
            'last_name' => $operator['last_name'] ?? null,
            'age' => $operator['age'] ?? null,
            'gender' => $operator['gender'] ?? null,
            'contact_number' => $operator['contact_number'] ?? null,
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
        $mode = RegularTripScheduleMode::tryFrom((string) ($trip['schedule_mode'] ?? 'rush')) ?? RegularTripScheduleMode::Rush;

        DB::table('regular_trips')->where('id', $oldId)->delete();

        DB::table('regular_trips')->insert([
            'id' => $oldId,
            'organization_id' => $orgId,
            'boat_id' => $boatId,
            'route' => $trip['route'],
            'direction' => $trip['direction'],
            'rotation_order' => (int) ($trip['rotation_order'] ?? 1),
            'schedule_mode' => $mode->value,
            'trip_date' => $trip['trip_date'],
            'departure_time' => '00:00:00',
            'passenger_count' => (int) ($trip['passenger_count'] ?? 0),
            'status' => $status->value,
            'remarks' => $trip['remarks'] ?? null,
            'is_auto_generated' => true,
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
