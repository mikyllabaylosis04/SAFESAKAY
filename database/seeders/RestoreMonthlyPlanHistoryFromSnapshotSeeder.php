<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PlatformMonthlyInvoice;
use App\Models\PlatformSubscriptionSetting;
use Illuminate\Database\Seeder;

class RestoreMonthlyPlanHistoryFromSnapshotSeeder extends RestoreSuperAdminHistoryFromSnapshotSeeder
{
    public function run(): void
    {
        $orgByName = [];
        foreach (Organization::query()->tenants()->get() as $organization) {
            $orgByName[$organization->name] = $organization;
        }

        $this->syncMonthlyPlanFromSnapshot($orgByName);

        $settings = PlatformSubscriptionSetting::current();

        $this->command?->info(sprintf(
            'Monthly plan restored: billing %s, fee ₱%s, %d orgs on plan, %d invoices.',
            $settings->billing_enabled ? 'on' : 'off',
            $settings->monthly_fee,
            OrganizationSubscription::query()->count(),
            PlatformMonthlyInvoice::query()->count(),
        ));
    }
}
