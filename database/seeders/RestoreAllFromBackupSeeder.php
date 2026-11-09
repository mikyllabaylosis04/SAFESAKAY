<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Full restore to match Super Admin screenshots (Oct 26, 2026):
 * 3 orgs, dashboard stats (8 boats, 42 rentals, 1 active weather hold),
 * Lakandula closure history, 6 weather holds, 3 barangay admins, monthly plan off.
 */
class RestoreAllFromBackupSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RestoreSuperAdminPlatformSeeder::class,
            RestoreSuperAdminHistoryFromSnapshotSeeder::class,
            RestoreLakandulaBoatsFromSnapshotSeeder::class,
            RestoreBarangayTaftaFromSnapshotSeeder::class,
            RestoreMonthlyPlanHistoryFromSnapshotSeeder::class,
            RestoreLakandulaUserHistorySeeder::class,
            RestoreCaicaiphamRentalHistorySeeder::class,
            RestoreBetitoRentalHistorySeeder::class,
            RestoreLakandulaOperatorGcashSeeder::class,
        ]);
    }
}
