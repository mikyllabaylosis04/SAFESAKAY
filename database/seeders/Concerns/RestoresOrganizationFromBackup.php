<?php

namespace Database\Seeders\Concerns;

use App\Models\Boat;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;

trait RestoresOrganizationFromBackup
{
    protected function restoreOrganizationRecords(string $backup, int $sourceOrgId, Organization $organization): void
    {
        $targetOrgId = $organization->id;

        $this->restoreOrganizationRoutes($backup, $sourceOrgId, $targetOrgId);
        $boatIds = $this->restoreBoats($backup, $sourceOrgId, $targetOrgId);
        $this->restoreOperators($backup, $boatIds);
        $this->restoreRegularTrips($backup, $sourceOrgId, $targetOrgId, $boatIds);
    }

    protected function organizationFromBackupRow(string $backup, int $sourceOrgId): ?Organization
    {
        foreach ($this->rowsForTable($backup, 'organizations') as $row) {
            if ((int) ($row[0] ?? 0) !== $sourceOrgId) {
                continue;
            }

            $slug = $this->string($row[3]);

            return Organization::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'tenant_id' => $this->string($row[1]),
                    'name' => $this->string($row[2]),
                    'address' => $this->string($row[4]),
                    'contact_number' => $this->string($row[5]),
                    'is_active' => true,
                    'approved_at' => now(),
                ],
            );
        }

        return null;
    }

    private function restoreOrganizationRoutes(string $backup, int $sourceOrgId, int $targetOrgId): void
    {
        OrganizationRoute::query()->where('organization_id', $targetOrgId)->delete();

        foreach ($this->rowsForTable($backup, 'organization_routes') as $row) {
            if ((int) ($row[1] ?? 0) !== $sourceOrgId) {
                continue;
            }

            OrganizationRoute::query()->create([
                'organization_id' => $targetOrgId,
                'name' => $this->string($row[2]),
                'origin' => $this->string($row[3]),
                'destination' => $this->string($row[4]),
                'trip_type' => $this->string($row[5] ?? 'regular_public'),
                'is_active' => (bool) ($row[6] ?? true),
                'sort_order' => (int) ($row[7] ?? 1),
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function restoreBoats(string $backup, int $sourceOrgId, int $targetOrgId): array
    {
        RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $targetOrgId)->forceDelete();
        Boat::query()->withoutGlobalScope('organization')->where('organization_id', $targetOrgId)->forceDelete();

        $map = [];

        foreach ($this->rowsForTable($backup, 'boats') as $row) {
            if ((int) ($row[1] ?? 0) !== $sourceOrgId) {
                continue;
            }

            $oldId = (int) $row[0];
            $boat = Boat::query()->create([
                'organization_id' => $targetOrgId,
                'name' => $this->string($row[2]),
                'code' => $this->string($row[3]),
                'capacity' => (int) ($row[4] ?? 20),
                'type' => $this->string($row[5] ?? 'Pump Boat'),
                'license_no' => $this->string($row[6] ?? 'LIC-00000'),
                'registration_date' => $this->nullableString($row[7]) ?? now()->toDateString(),
                'status' => $this->string($row[8] ?? 'available'),
                'registration_status' => $this->string($row[9] ?? 'registered'),
                'service_type' => $this->string($row[10] ?? 'regular_trip'),
                'service_availability' => $this->decodeJson($row[11]),
                'picture_path' => $this->nullableString($row[12]),
                'is_active' => (bool) ($row[17] ?? true),
            ]);

            $map[$oldId] = $boat->id;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $boatIds
     */
    private function restoreOperators(string $backup, array $boatIds): void
    {
        $oldBoatIds = array_keys($boatIds);

        foreach ($this->rowsForTable($backup, 'operators') as $row) {
            $boatId = (int) ($row[1] ?? 0);
            if (! in_array($boatId, $oldBoatIds, true)) {
                continue;
            }

            $newBoatId = $boatIds[$boatId];

            Operator::query()->updateOrCreate(
                ['boat_id' => $newBoatId],
                [
                    'full_name' => $this->string($row[3] ?? 'Operator'),
                    'first_name' => $this->nullableString($row[4]),
                    'middle_name' => $this->nullableString($row[5]),
                    'last_name' => $this->nullableString($row[6]),
                    'age' => $this->nullableInt($row[7]),
                    'gender' => $this->nullableString($row[8]),
                    'contact_number' => $this->nullableString($row[9]) ?? '09000000000',
                    'address' => $this->nullableString($row[10]),
                ],
            );
        }
    }

    /**
     * @param  array<int, int>  $boatIds
     */
    private function restoreRegularTrips(string $backup, int $sourceOrgId, int $targetOrgId, array $boatIds): void
    {
        foreach ($this->rowsForTable($backup, 'regular_trips') as $row) {
            if ((int) ($row[1] ?? 0) !== $sourceOrgId) {
                continue;
            }

            $oldBoatId = (int) ($row[2] ?? 0);
            if (! isset($boatIds[$oldBoatId])) {
                continue;
            }

            RegularTrip::query()->create([
                'organization_id' => $targetOrgId,
                'boat_id' => $boatIds[$oldBoatId],
                'route' => $this->string($row[3]),
                'direction' => $this->string($row[4]),
                'rotation_order' => (int) ($row[5] ?? 1),
                'schedule_mode' => $this->string($row[6] ?? 'timed'),
                'trip_date' => $this->string($row[7]),
                'departure_time' => $this->string($row[8] ?? '00:00:00'),
                'passenger_count' => (int) ($row[9] ?? 0),
                'status' => $this->string($row[10] ?? 'scheduled'),
                'remarks' => $this->nullableString($row[11]),
                'is_auto_generated' => (bool) ($row[12] ?? true),
            ]);
        }
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsForTable(string $backup, string $table): array
    {
        if (! preg_match('/INSERT INTO `'.$table.'` VALUES (.*?);/s', $backup, $match)) {
            return [];
        }

        $tuples = $this->splitSqlTuples($match[1]);
        $rows = [];

        foreach ($tuples as $tuple) {
            $rows[] = str_getcsv(trim($tuple, '()'), ',', "'", '\\');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function splitSqlTuples(string $values): array
    {
        $tuples = [];
        $depth = 0;
        $current = '';
        $inString = false;
        $escape = false;

        $length = strlen($values);

        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];

            if ($escape) {
                $current .= $char;
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escape = true;

                continue;
            }

            if ($char === "'") {
                $inString = ! $inString;
                $current .= $char;

                continue;
            }

            if (! $inString && $char === '(') {
                if ($depth === 0) {
                    $current = '';
                } else {
                    $current .= $char;
                }
                $depth++;

                continue;
            }

            if (! $inString && $char === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $current;
                    $current = '';

                    continue;
                }
                $current .= $char;

                continue;
            }

            if ($depth > 0) {
                $current .= $char;
            }
        }

        return $tuples;
    }

    private function string(mixed $value): string
    {
        if ($value === null || $value === 'NULL') {
            return '';
        }

        return (string) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === 'NULL' || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === 'NULL' || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, string>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === 'NULL') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function loadBackupSql(): string
    {
        $backup = file_get_contents(storage_path('safesakayvue_restored_backup.sql'));

        if ($backup === false) {
            throw new \RuntimeException('Backup file missing: storage/safesakayvue_restored_backup.sql');
        }

        return $backup;
    }
}
