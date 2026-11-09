<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationRoute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RestoreLakandulaRoutesFromSnapshotSeeder extends Seeder
{
    /** Regular + exclusive rental destinations (mobile destination picker). */
    public const ROUTES_SNAPSHOT = 'inertia-devtools/01M5WBAN1N4NMYYPGSHPW1A5D4.json';

    public function run(): void
    {
        $organization = Organization::query()->where('name', 'BARANGAY LAKANDULA')->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $routes = $this->loadProps(self::ROUTES_SNAPSHOT)['routes'] ?? [];

        if ($routes === []) {
            $this->command?->warn('No routes in snapshot.');

            return;
        }

        DB::transaction(function () use ($organization, $routes): void {
            $snapshotIds = [];

            foreach ($routes as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                $payload = $this->routePayload($organization->id, $row, $index);

                OrganizationRoute::query()
                    ->where('organization_id', $organization->id)
                    ->where('name', $payload['name'])
                    ->when($id > 0, fn ($query) => $query->where('id', '!=', $id))
                    ->delete();

                if ($id > 0) {
                    $snapshotIds[] = $id;
                    OrganizationRoute::query()->updateOrCreate(
                        ['id' => $id],
                        array_merge($payload, ['id' => $id]),
                    );
                } else {
                    OrganizationRoute::query()->create($payload);
                }
            }

            if ($snapshotIds !== []) {
                OrganizationRoute::query()
                    ->where('organization_id', $organization->id)
                    ->whereNotIn('id', $snapshotIds)
                    ->delete();
            }
        });

        $this->command?->info('Restored '.count($routes).' Lakandula route(s) from snapshot.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function routePayload(int $organizationId, array $row, int $index): array
    {
        $origin = (string) ($row['origin'] ?? 'Lakandula');
        $destination = (string) ($row['destination'] ?? 'Placer');

        return [
            'organization_id' => $organizationId,
            'name' => (string) ($row['name'] ?? OrganizationRoute::makeName($origin, $destination)),
            'origin' => $origin,
            'destination' => $destination,
            'trip_type' => (string) ($row['trip_type'] ?? 'regular_public'),
            'regular_fare' => $row['regular_fare'] ?? null,
            'student_discount_percent' => $row['student_discount_percent'] ?? 20,
            'senior_discount_percent' => $row['senior_discount_percent'] ?? 20,
            'rental_price' => $row['rental_price'] ?? null,
            'down_payment_percent' => $row['down_payment_percent'] ?? null,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
        ];
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
}
