<?php

namespace Database\Seeders;

use App\Models\Boat;
use App\Models\Organization;
use App\Models\RegularTrip;
use Database\Seeders\Concerns\RestoresOrganizationFromBackup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestoreLakandula707RecordsSeeder extends Seeder
{
    use RestoresOrganizationFromBackup;

    private const SOURCE_ORG_ID = 3;

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

        $backup = $this->loadBackupSql();

        DB::transaction(function () use ($organization, $backup): void {
            $this->restoreOrganizationRecords($backup, self::SOURCE_ORG_ID, $organization);
        });

        $boats = Boat::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();
        $trips = RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();

        $this->command?->info("Restored Lakandula org #{$organization->id}: {$boats} boats, {$trips} trips.");
    }
}
