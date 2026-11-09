<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\OrganizationSubscription;
use App\Models\PlatformMonthlyInvoice;
use App\Models\PlatformSubscriptionSetting;
use App\Models\User;
use App\Models\WeatherSuspension;
use App\Enums\PlatformInvoiceStatus;
use Carbon\Carbon;
use Database\Seeders\Concerns\LoadsLakandulaBookingsFromSnapshots;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RestoreSuperAdminHistoryFromSnapshotSeeder extends Seeder
{
    use LoadsLakandulaBookingsFromSnapshots;

    /** Full Super Admin Organizations page (8 barangays, Oct 26 2026). */
    private const ORGANIZATIONS_SNAPSHOT = 'inertia-devtools/01M5WBM077VYQBGVVY9FWYVTWM.json';

    private const FULL_ACCESS_SNAPSHOT = 'inertia-devtools/01M5WAXJ15EDJQS2S2BGG2WQKW.json';

    private const ADMINS_SNAPSHOT = 'inertia-devtools/01M5WFYE26YAAV1EAB4SCBT324.json';

    private const WEATHER_PLATFORM_SNAPSHOT = 'inertia-devtools/01M5WFZ4SG2RTZ5YQWRECA69Q5.json';

    private const WEATHER_DETAIL_SNAPSHOT = 'inertia-devtools/01M5VWY62ZDD7KA8CE756VDCRE.json';

    /** Billing on, ₱500, GCash QR, payment history (Oct 26). */
    private const SUBSCRIPTIONS_SNAPSHOT = 'inertia-devtools/01M5WKV5JYGWDNH77CY2S21FG1.json';

    /** All barangays on the monthly plan list. */
    private const SUBSCRIPTIONS_ORGANIZATIONS_SNAPSHOT = 'inertia-devtools/01M5WPZ37BF5G481M0KS429D6Z.json';

    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        $orgByName = $this->syncOrganizations();
        $this->syncBarangayAdmins($orgByName);
        $this->syncPlatformEvents($orgByName);
        $this->syncLakandulaClosureState($orgByName);
        $this->syncLakandulaWeatherHolds($orgByName);
        $this->syncMonthlyPlanFromSnapshot($orgByName);

        $maxEventId = (int) DB::table('platform_events')->max('id');
        if ($maxEventId > 0) {
            DB::statement('ALTER TABLE platform_events AUTO_INCREMENT = '.($maxEventId + 1));
        }

        $eventCount = PlatformEvent::query()->count();
        $holdCount = WeatherSuspension::query()->withoutGlobalScope('organization')->count();
        $tenantCount = Organization::query()->tenants()->count();

        $this->command?->info("Super Admin history restored: {$tenantCount} tenant orgs, {$eventCount} platform events, {$holdCount} weather holds.");
    }

    /**
     * @return array<string, Organization>
     */
    private function syncOrganizations(): array
    {
        $props = $this->loadSnapshotProps(self::ORGANIZATIONS_SNAPSHOT);
        $rows = $props['organizations'] ?? [];
        $map = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $map[$name] = $this->upsertOrganizationFromSnapshotRow($row);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertOrganizationFromSnapshotRow(array $row): Organization
    {
        $name = (string) ($row['name'] ?? '');
        $snapshotId = (int) ($row['id'] ?? 0);
        $isActive = (bool) ($row['is_active'] ?? true);
        $accessState = (string) ($row['access_state'] ?? ($isActive ? 'active' : 'suspended'));

        $organization = Organization::query()->whereRaw('BINARY name = ?', [$name])->first();
        if (! $organization && $snapshotId > 0) {
            $byId = Organization::query()->find($snapshotId);
            if ($byId && strcasecmp($byId->name, $name) === 0) {
                $organization = $byId;
            }
        }

        $approvedAt = $organization?->approved_at
            ?? $this->parseLabelDate((string) ($row['last_change_at_label'] ?? ''))
            ?? now();

        $attributes = [
            'name' => $name,
            'address' => (string) ($row['address'] ?? ''),
            'contact_number' => (string) ($row['contact_number'] ?? ''),
            'is_active' => $isActive,
            'approved_at' => $approvedAt,
        ];

        if ($accessState === 'suspended' || ($isActive === false && $accessState !== 'archived' && $accessState !== 'inactive')) {
            $attributes['inactive_kind'] = null;
            $attributes['suspended_at'] = $this->parseLabelDate((string) ($row['last_change_at_label'] ?? ''))
                ?? $organization?->suspended_at
                ?? now();
            $attributes['suspended_reason'] = filled($row['last_change_note'] ?? null)
                ? (string) $row['last_change_note']
                : $organization?->suspended_reason;
        } elseif ($isActive) {
            $attributes['inactive_kind'] = null;
            $attributes['suspended_at'] = null;
            $attributes['suspended_reason'] = null;
        }

        if (! $organization) {
            $organization = Organization::query()->create($attributes);
        } else {
            $organization->update($attributes);
        }

        return $organization->fresh();
    }

    /**
     * @param  array<string, Organization>  $orgByName
     */
    private function syncBarangayAdmins(array $orgByName): void
    {
        foreach ($this->loadSnapshotProps(self::ORGANIZATIONS_SNAPSHOT)['organizations'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $email = (string) ($row['admin_email'] ?? '');
            $orgName = (string) ($row['name'] ?? '');
            if ($email === '' || $orgName === '') {
                continue;
            }

            $organization = $orgByName[$orgName] ?? $this->organizationByExactName($orgName);
            if (! $organization) {
                continue;
            }

            $this->upsertBarangayAdmin(
                $email,
                (string) ($row['admin_name'] ?? 'Barangay Admin'),
                $organization,
                (bool) ($row['is_active'] ?? true),
            );
        }

        $props = $this->loadSnapshotProps(self::ADMINS_SNAPSHOT);
        $rows = $props['admins'] ?? [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $email = (string) ($row['email'] ?? '');
            $orgName = (string) ($row['organization_name'] ?? '');
            if ($email === '' || $orgName === '') {
                continue;
            }

            $organization = $orgByName[$orgName] ?? $this->organizationByExactName($orgName);
            if (! $organization) {
                continue;
            }

            $this->upsertBarangayAdmin(
                $email,
                (string) ($row['name'] ?? 'Barangay Admin'),
                $organization,
                (bool) ($row['is_active'] ?? true),
            );
        }
    }

    private function upsertBarangayAdmin(string $email, string $name, Organization $organization, bool $isActive): void
    {
        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'organization_id' => $organization->id,
                'name' => $name,
                'username' => User::uniqueUsernameFromEmail($email),
                'password' => Hash::make($email === 'lakandula707@gmail.com' ? 'lakandula707' : 'password'),
                'role' => UserRole::Administrator,
                'is_active' => $isActive,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, Organization>  $orgByName
     */
    private function syncPlatformEvents(array $orgByName): void
    {
        $events = [];
        $seen = [];

        foreach ($this->loadSnapshotProps(self::ORGANIZATIONS_SNAPSHOT)['access_history'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = (string) ($row['id'] ?? '').'|'.($row['action'] ?? '').'|'.($row['created_at_label'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $events[] = $row;
        }

        PlatformEvent::query()->delete();

        $superAdmin = User::query()->where('email', config('safesakay.super_admin_email'))->first();

        foreach ($events as $row) {
            $orgName = (string) ($row['organization_name'] ?? '');
            $organization = $orgByName[$orgName] ?? $this->organizationByExactName($orgName);
            $actorName = (string) ($row['actor_name'] ?? '');
            $actor = $this->resolveActor($actorName, $organization, $superAdmin);

            $createdAt = $this->parseLabelDate((string) ($row['created_at_label'] ?? '')) ?? now();

            $payload = [
                'actor_id' => $actor?->id,
                'organization_id' => $organization?->id,
                'organization_name' => $orgName,
                'action' => (string) ($row['action'] ?? ''),
                'note' => $row['note'] ?? null,
                'created_at' => $createdAt,
            ];

            $eventId = (int) ($row['id'] ?? 0);
            if ($eventId > 0) {
                DB::table('platform_events')->insert([...$payload, 'id' => $eventId]);
            } else {
                PlatformEvent::query()->create($payload);
            }
        }
    }

    /**
     * @param  array<string, Organization>  $orgByName
     */
    private function syncLakandulaClosureState(array $orgByName): void
    {
        $lakandula = $orgByName['BARANGAY LAKANDULA'] ?? $this->organizationByExactName('BARANGAY LAKANDULA');
        if (! $lakandula) {
            return;
        }

        $rejectedAt = $this->parseLabelDate('Oct 26, 2026 · 3:41 PM') ?? now();

        $lakandula->update([
            'closure_requested_at' => null,
            'closure_reason' => null,
            'closure_details' => null,
            'closure_requested_by' => null,
            'closure_rejected_at' => $rejectedAt,
            'closure_rejection_reason' => 'Closure request was rejected.',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, Organization>  $orgByName
     */
    private function syncLakandulaWeatherHolds(array $orgByName): void
    {
        $lakandula = $orgByName['BARANGAY LAKANDULA'] ?? $this->organizationByExactName('BARANGAY LAKANDULA');
        if (! $lakandula) {
            return;
        }

        $admin = User::query()
            ->where('email', 'lakandula707@gmail.com')
            ->where('organization_id', $lakandula->id)
            ->first();

        $detailProps = $this->loadSnapshotProps(self::WEATHER_DETAIL_SNAPSHOT);
        $detailsByCode = [];
        foreach ($detailProps['weatherSuspensions'] ?? [] as $row) {
            if (is_array($row) && isset($row['code'])) {
                $detailsByCode[(string) $row['code']] = $row;
            }
        }

        $platformProps = $this->loadSnapshotProps(self::WEATHER_PLATFORM_SNAPSHOT);
        $holds = $platformProps['holds'] ?? [];
        $codes = [];

        foreach ($holds as $hold) {
            if (! is_array($hold)) {
                continue;
            }

            $code = (string) ($hold['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $codes[] = $code;
            $detail = $detailsByCode[$code] ?? [];

            $status = WeatherSuspensionStatus::tryFrom((string) ($hold['status'] ?? $detail['status'] ?? 'active'))
                ?? WeatherSuspensionStatus::Active;
            $reason = WeatherHoldReason::tryFrom((string) ($detail['reason'] ?? 'bad_weather'))
                ?? WeatherHoldReason::BadWeather;

            $affectedDate = $detail['affected_date']
                ?? $this->parseLabelDate((string) ($hold['affected_date_label'] ?? ''))?->toDateString()
                ?? now()->toDateString();

            WeatherSuspension::query()->updateOrCreate(
                [
                    'organization_id' => $lakandula->id,
                    'code' => $code,
                ],
                [
                    'declared_by' => $admin?->id ?? 1,
                    'announcement_id' => null,
                    'reason' => $reason,
                    'affected_date' => $affectedDate,
                    'affected_routes' => $detail['affected_routes'] ?? ['all'],
                    'duration_type' => $detail['duration_type'] ?? 'whole_day',
                    'starts_at' => null,
                    'ends_at' => null,
                    'announcement_message' => (string) ($detail['announcement_message'] ?? 'Bad weather suspension.'),
                    'status' => $status,
                    'affected_trips_count' => (int) ($hold['trips_count'] ?? $detail['affected_trips_count'] ?? 0),
                    'affected_rentals_count' => (int) ($hold['rentals_count'] ?? $detail['affected_rentals_count'] ?? 0),
                    'review_flagged_count' => (int) ($detail['review_flagged_count'] ?? 0),
                    'lifted_by' => $status === WeatherSuspensionStatus::Lifted ? ($admin?->id) : null,
                    'lifted_at' => $status === WeatherSuspensionStatus::Lifted ? Carbon::parse($affectedDate)->endOfDay() : null,
                ],
            );
        }

        if ($codes !== []) {
            WeatherSuspension::query()
                ->where('organization_id', $lakandula->id)
                ->whereNotIn('code', $codes)
                ->delete();
        }
    }

    /**
     * @param  array<string, Organization>  $orgByName
     */
    protected function syncMonthlyPlanFromSnapshot(array $orgByName): void
    {
        $props = $this->loadSnapshotProps(self::SUBSCRIPTIONS_SNAPSHOT);
        $settings = $props['settings'] ?? [];
        $qrPath = $this->gcashQrPathFromSnapshotUrl($settings['gcash_qr_url'] ?? null);

        PlatformSubscriptionSetting::current()->update([
            'billing_enabled' => (bool) ($settings['billing_enabled'] ?? false),
            'monthly_fee' => number_format((float) ($settings['monthly_fee'] ?? 0), 2, '.', ''),
            'gcash_account_name' => filled($settings['gcash_account_name'] ?? null)
                ? (string) $settings['gcash_account_name']
                : null,
            'gcash_qr_path' => $qrPath,
        ]);

        $organizationRows = $this->loadSnapshotProps(self::SUBSCRIPTIONS_ORGANIZATIONS_SNAPSHOT)['organizations']
            ?? $props['organizations']
            ?? [];

        foreach ($organizationRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $organization = $orgByName[$name] ?? $this->organizationByExactName($name);
            if (! $organization) {
                continue;
            }

            OrganizationSubscription::query()->updateOrCreate(
                ['organization_id' => $organization->id],
                ['billed' => (bool) ($row['billed'] ?? true)],
            );
        }

        $invoices = $props['invoices'] ?? [];
        PlatformMonthlyInvoice::query()->delete();

        $superAdmin = User::query()->where('email', config('safesakay.super_admin_email'))->first();

        foreach ($invoices as $row) {
            if (! is_array($row)) {
                continue;
            }

            $orgName = (string) ($row['organization_name'] ?? '');
            $organization = $orgByName[$orgName] ?? $this->organizationByExactName($orgName);
            if (! $organization) {
                continue;
            }

            $status = PlatformInvoiceStatus::tryFrom((string) ($row['status'] ?? 'due'))
                ?? PlatformInvoiceStatus::Due;
            $period = (string) ($row['period'] ?? now()->format('Y-m'));
            $periodStart = Carbon::createFromFormat('Y-m', $period)?->startOfMonth()->toDateString()
                ?? now()->startOfMonth()->toDateString();

            $payload = [
                'organization_id' => $organization->id,
                'period' => $period,
                'period_start' => $periodStart,
                'due_on' => $row['due_on'] ?? Carbon::createFromFormat('Y-m', $period)?->endOfMonth()->toDateString(),
                'amount' => number_format((float) ($row['amount_raw'] ?? $row['amount'] ?? 0), 2, '.', ''),
                'status' => $status,
                'gcash_reference' => $row['gcash_reference'] ?? null,
                'proof_path' => null,
                'submitted_at' => isset($row['submitted_at']) ? $this->parseLabelDate((string) $row['submitted_at']) : null,
                'verified_by' => $status === PlatformInvoiceStatus::Paid ? $superAdmin?->id : null,
                'verified_at' => isset($row['verified_at_label'])
                    ? $this->parseLabelDate((string) $row['verified_at_label'])
                    : null,
                'rejection_reason' => $row['rejection_reason'] ?? null,
            ];

            $invoiceId = (int) ($row['id'] ?? 0);
            if ($invoiceId > 0) {
                PlatformMonthlyInvoice::query()->where('id', $invoiceId)->delete();
                $payload['id'] = $invoiceId;
                PlatformMonthlyInvoice::query()->create($payload);
            } else {
                PlatformMonthlyInvoice::query()->create($payload);
            }
        }
    }

    private function resolveActor(string $actorName, ?Organization $organization, ?User $superAdmin): ?User
    {
        if (str_contains(strtolower($actorName), 'super admin')) {
            return $superAdmin;
        }

        if ($organization) {
            $admin = $organization->users()
                ->where('role', UserRole::Administrator)
                ->orderBy('id')
                ->first();

            if ($admin) {
                return $admin;
            }
        }

        return $superAdmin;
    }

    private function parseLabelDate(string $label): ?Carbon
    {
        if ($label === '') {
            return null;
        }

        $normalized = str_replace(['·', '–'], ' ', $label);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $label;

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    private function organizationByExactName(string $name): ?Organization
    {
        if ($name === '') {
            return null;
        }

        return Organization::query()->whereRaw('BINARY name = ?', [$name])->first();
    }

    private function gcashQrPathFromSnapshotUrl(mixed $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $path = ltrim(str_replace('/storage/', '', (string) $url), '/');

        if ($path === '' || ! is_file(storage_path('app/public/'.$path))) {
            return null;
        }

        return $path;
    }
}
