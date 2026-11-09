<?php

namespace Database\Seeders;

use App\Models\Boat;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class RestoreLakandulaBoatRentalDestinationsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'BARANGAY LAKANDULA')->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $boats = $this->loadProps(RestoreLakandulaBoatsFromSnapshotSeeder::BOATS_SNAPSHOT)['boats'] ?? [];

        if ($boats === []) {
            $this->command?->warn('No boats in snapshot.');

            return;
        }

        $validRouteIds = OrganizationRoute::query()
            ->where('organization_id', $organization->id)
            ->where('trip_type', 'private_booking')
            ->pluck('id')
            ->all();

        $linked = 0;

        foreach ($boats as $row) {
            if (! is_array($row)) {
                continue;
            }

            $boatId = (int) ($row['id'] ?? 0);
            if ($boatId <= 0) {
                continue;
            }

            $boat = Boat::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)
                ->where('id', $boatId)
                ->first();

            if (! $boat) {
                continue;
            }

            $destinationIds = $this->destinationIdsFromSnapshotRow($row);
            $destinationIds = array_values(array_intersect($destinationIds, $validRouteIds));

            if ($destinationIds === []) {
                continue;
            }

            $boat->rentalDestinations()->sync($destinationIds);
            $linked++;
        }

        $this->command?->info("Linked rental destinations on {$linked} Lakandula boat(s).");
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<int>
     */
    private function destinationIdsFromSnapshotRow(array $row): array
    {
        $ids = collect($row['rental_destination_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        return collect($row['rental_destinations'] ?? [])
            ->filter(fn ($dest) => is_array($dest))
            ->map(fn (array $dest) => (int) ($dest['id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
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
