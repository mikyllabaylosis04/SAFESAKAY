<?php

namespace Database\Seeders;

use App\Enums\OperatorGcashStatus;
use App\Models\Boat;
use App\Models\Operator;
use App\Models\OperatorGcashAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Concerns\LoadsLakandulaBookingsFromSnapshots;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestoreLakandulaOperatorGcashSeeder extends Seeder
{
    use LoadsLakandulaBookingsFromSnapshots;

    public const PAYMENTS_SNAPSHOT = 'inertia-devtools/01M5VXN77AVFXJ061TCG2JR013.json';

    public function run(): void
    {
        $organization = Organization::query()
            ->where('name', 'BARANGAY LAKANDULA')
            ->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $props = $this->loadSnapshotProps(self::PAYMENTS_SNAPSHOT);
        $accounts = $props['operator_gcash_accounts'] ?? [];

        if ($accounts === []) {
            $this->command?->error('No operator GCash accounts in snapshot.');

            return;
        }

        $admin = User::query()->where('email', EnsureLakandula707AdminSeeder::ADMIN_EMAIL)->first();
        $orgId = $organization->id;

        $operatorIds = Operator::query()
            ->whereIn('boat_id', Boat::query()->withoutGlobalScope('organization')->where('organization_id', $orgId)->pluck('id'))
            ->pluck('id');

        OperatorGcashAccount::query()->whereIn('operator_id', $operatorIds)->delete();

        if (($props['gcash_account_name'] ?? '') !== '') {
            $organization->update([
                'gcash_account_name' => $props['gcash_account_name'],
            ]);
        }

        $restored = 0;

        foreach ($accounts as $row) {
            if (! is_array($row)) {
                continue;
            }

            $boatCode = (string) ($row['boat_code'] ?? '');
            $operator = Operator::query()
                ->whereHas('boat', function ($query) use ($orgId, $boatCode): void {
                    $query->withoutGlobalScope('organization')
                        ->where('organization_id', $orgId)
                        ->where('code', $boatCode);
                })
                ->first();

            if (! $operator) {
                $this->command?->warn('No operator for GCash row boat '.$boatCode);

                continue;
            }

            $status = OperatorGcashStatus::tryFrom((string) ($row['status'] ?? 'pending_approval'))
                ?? OperatorGcashStatus::PendingApproval;
            $submittedAt = $this->parseDateTime((string) ($row['submitted_at'] ?? '')) ?? now();
            $reviewedAt = $status === OperatorGcashStatus::Approved ? $submittedAt : null;

            $payload = [
                'operator_id' => $operator->id,
                'organization_id' => $orgId,
                'account_name' => $row['account_name'],
                'mobile_number' => $row['mobile_number'],
                'qr_path' => $this->storagePathFromUrl((string) ($row['qr_url'] ?? '')) ?? 'operator-gcash/placeholder.png',
                'status' => $status->value,
                'submitted_at' => $submittedAt,
                'reviewed_at' => $reviewedAt,
                'reviewed_by' => $reviewedAt && $admin ? $admin->id : null,
                'rejection_reason' => null,
                'created_at' => $submittedAt,
                'updated_at' => $reviewedAt ?? $submittedAt,
            ];

            $accountId = (int) ($row['id'] ?? 0);
            if ($accountId > 0) {
                DB::table('operator_gcash_accounts')->where('id', $accountId)->delete();
                $payload['id'] = $accountId;
            }

            DB::table('operator_gcash_accounts')->insert($payload);

            $restored++;
        }

        $max = (int) DB::table('operator_gcash_accounts')->max('id');
        if ($max > 0) {
            DB::statement('ALTER TABLE operator_gcash_accounts AUTO_INCREMENT = '.($max + 1));
        }

        $this->command?->info("Restored {$restored} operator GCash accounts for Online Transaction.");
    }

    private function parseDateTime(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
}
