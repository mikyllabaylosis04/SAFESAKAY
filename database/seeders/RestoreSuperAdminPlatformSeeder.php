<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\Organization;
use App\Models\RegularTrip;
use App\Models\User;
use Database\Seeders\Concerns\RestoresOrganizationFromBackup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RestoreSuperAdminPlatformSeeder extends Seeder
{
    use RestoresOrganizationFromBackup;

    private const PLATFORM_ORG_ID = 1;

    private const EAST_THALIA_ORG_ID = 2;

    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        $backup = $this->loadBackupSql();

        DB::transaction(function () use ($backup): void {
            $platformOrg = $this->organizationFromBackupRow($backup, self::PLATFORM_ORG_ID);
            $eastThalia = $this->organizationFromBackupRow($backup, self::EAST_THALIA_ORG_ID);

            if ($platformOrg) {
                $this->restoreOrganizationRecords($backup, self::PLATFORM_ORG_ID, $platformOrg);
            }

            if ($eastThalia) {
                $this->restoreOrganizationRecords($backup, self::EAST_THALIA_ORG_ID, $eastThalia);
                $this->restoreBarangayAdminFromBackup($backup, self::EAST_THALIA_ORG_ID, $eastThalia);
            }
        });

        $superEmail = (string) config('safesakay.super_admin_email');
        $orgCount = Organization::query()->count();
        $east = Organization::query()->where('slug', 'barangay-east-thalia-155')->first();

        $this->command?->info("Super Admin restored: {$superEmail} (password: SUPER_ADMIN_PASSWORD or SafeSakay@Super1).");
        $this->command?->info("Platform organizations in database: {$orgCount}.");

        if ($east) {
            $boats = Boat::query()->withoutGlobalScope('organization')->where('organization_id', $east->id)->count();
            $trips = RegularTrip::query()->withoutGlobalScope('organization')->where('organization_id', $east->id)->count();
            $this->command?->info("East Thalia org #{$east->id}: {$boats} boats, {$trips} trips. Admin: emely22@example.org / password");
        }
    }

    private function restoreBarangayAdminFromBackup(string $backup, int $sourceOrgId, Organization $organization): void
    {
        foreach ($this->rowsForTable($backup, 'users') as $row) {
            if ((int) ($row[1] ?? 0) !== $sourceOrgId) {
                continue;
            }

            $email = $this->string($row[4]);
            if ($email === '') {
                continue;
            }

            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'organization_id' => $organization->id,
                    'name' => $this->string($row[2]),
                    'username' => $this->nullableString($row[3]),
                    'password' => Hash::make('password'),
                    'role' => UserRole::Administrator,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
