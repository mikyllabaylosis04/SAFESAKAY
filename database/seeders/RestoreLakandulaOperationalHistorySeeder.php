<?php

namespace Database\Seeders;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementStatus;
use App\Enums\GcashPayee;
use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Announcement;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BoatBookingActivity;
use App\Models\Organization;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Models\User;
use App\Models\WeatherSuspension;
use Carbon\Carbon;
use Database\Seeders\Concerns\LoadsLakandulaBookingsFromSnapshots;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestoreLakandulaOperationalHistorySeeder extends Seeder
{
    use LoadsLakandulaBookingsFromSnapshots;

    public const ANNOUNCEMENTS_SNAPSHOT = 'inertia-devtools/01M5VWY62ZDD7KA8CE756VDCRE.json';

    public function run(): void
    {
        $organization = Organization::query()
            ->where('name', 'BARANGAY LAKANDULA')
            ->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $admin = User::query()->where('email', EnsureLakandula707AdminSeeder::ADMIN_EMAIL)->first();
        $bookings = $this->loadMergedBookingsFromSnapshots();

        if ($bookings === []) {
            $this->command?->error('No rental bookings found in Inertia snapshots.');

            return;
        }

        $orgId = $organization->id;
        $bookingCount = 0;
        $commissionCount = 0;
        $activityCount = 0;

        DB::transaction(function () use ($orgId, $admin, $bookings, &$bookingCount, &$commissionCount, &$activityCount): void {
            RentalCommission::query()->where('organization_id', $orgId)->delete();
            $existingIds = BoatBooking::query()->where('organization_id', $orgId)->pluck('id');
            BoatBookingActivity::query()->whereIn('boat_booking_id', $existingIds)->delete();
            BoatBooking::query()->where('organization_id', $orgId)->delete();

            foreach ($bookings as $row) {
                $boatId = (int) ($row['boat']['id'] ?? 0);
                if ($boatId <= 0 || ! Boat::query()->withoutGlobalScope('organization')->whereKey($boatId)->exists()) {
                    $this->command?->warn('Skipping booking '.($row['code'] ?? '?').' — boat #'.$boatId.' missing.');

                    continue;
                }

                $payment = is_array($row['payment'] ?? null) ? $row['payment'] : [];
                $status = BoatBookingStatus::tryFrom((string) ($row['status'] ?? 'pending')) ?? BoatBookingStatus::Pending;
                $paymentStatus = BookingPaymentStatus::tryFrom((string) ($payment['payment_status'] ?? 'unpaid'))
                    ?? BookingPaymentStatus::Unpaid;
                $balanceStatus = BookingBalanceStatus::tryFrom((string) ($payment['balance_status'] ?? 'unpaid'))
                    ?? BookingBalanceStatus::Unpaid;
                $gcashPayee = GcashPayee::tryFrom((string) ($payment['gcash_payee'] ?? ''));
                $percent = $this->decimalFromPercentLabel((string) ($payment['commission_percentage_label'] ?? ''));
                $createdAt = $this->parseDateTime((string) ($row['created_at'] ?? ''))
                    ?? (isset($row['created_at_unix']) ? Carbon::createFromTimestamp((int) $row['created_at_unix']) : now());

                $submittedAt = $this->activityTime($row, 'payment_submitted');
                $verifiedAt = $this->activityTime($row, 'payment_verified');

                DB::table('boat_bookings')->insert([
                    'id' => (int) $row['id'],
                    'organization_id' => $orgId,
                    'code' => $row['code'],
                    'boat_id' => $boatId,
                    'user_id' => null,
                    'customer_name' => $row['customer_name'],
                    'contact_number' => $row['contact_number'] ?? null,
                    'package' => $row['package'] ?? 'Rental',
                    'destination' => $row['destination'] ?? null,
                    'pickup_point' => $row['pickup_point'] ?? null,
                    'purpose' => $row['purpose'] ?? null,
                    'booking_date' => $row['booking_date'],
                    'original_booking_date' => $row['original_booking_date'] ?? $row['booking_date'],
                    'start_time' => $this->normalizeTime((string) ($row['start_time'] ?? '00:00')),
                    'passenger_count' => (int) ($row['passenger_count'] ?? 1),
                    'status' => $status->value,
                    'remarks' => $row['remarks'] ?? null,
                    'rental_price' => $payment['rental_price'] ?? null,
                    'down_payment_percent' => $payment['down_payment_percent'] ?? null,
                    'down_payment_amount' => $payment['down_payment_amount'] ?? null,
                    'remaining_balance' => $payment['remaining_balance'] ?? null,
                    'commission_enabled' => (bool) ($payment['commission_enabled'] ?? false),
                    'commission_percentage' => $percent,
                    'commission_amount' => $this->moneyRaw($payment['commission_amount_label'] ?? null),
                    'operator_net_amount' => $this->moneyRaw($payment['operator_net_label'] ?? null),
                    'gcash_payee' => $gcashPayee?->value,
                    'payment_status' => $paymentStatus->value,
                    'gcash_reference' => $payment['gcash_reference'] ?? null,
                    'payment_submitted_at' => $submittedAt,
                    'payment_verified_at' => $verifiedAt,
                    'payment_verified_by' => $verifiedAt && $admin ? $admin->id : null,
                    'payment_rejection_reason' => $payment['payment_rejection_reason'] ?? null,
                    'balance_status' => $balanceStatus->value,
                    'balance_method' => $payment['balance_method'] ?? null,
                    'balance_reference' => $payment['balance_reference'] ?? null,
                    'weather_reason' => $row['weather_reason'] ?? null,
                    'reschedule_status' => $row['reschedule_status'] ?? null,
                    'requested_booking_date' => $row['requested_booking_date'] ?? null,
                    'reschedule_rejection_reason' => $row['reschedule_rejection_reason'] ?? null,
                    'completed_at' => $status === BoatBookingStatus::Completed ? ($verifiedAt ?? $createdAt) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $verifiedAt ?? $createdAt,
                ]);

                $bookingCount++;

                foreach ($row['activities'] ?? [] as $activity) {
                    if (! is_array($activity)) {
                        continue;
                    }

                    $occurred = $this->parseDateTime((string) ($activity['occurred_at'] ?? '')) ?? $createdAt;

                    BoatBookingActivity::query()->create([
                        'boat_booking_id' => (int) $row['id'],
                        'event' => (string) ($activity['event'] ?? 'updated'),
                        'title' => (string) ($activity['title'] ?? 'Updated'),
                        'actor_name' => $activity['actor_name'] ?? null,
                        'created_at' => $occurred,
                        'updated_at' => $occurred,
                    ]);
                    $activityCount++;
                }

                $commissionId = (int) ($payment['commission_id'] ?? 0);
                if ($commissionId > 0 && ($payment['commission_enabled'] ?? false)) {
                    $boat = Boat::query()->withoutGlobalScope('organization')->with('operator')->find($boatId);
                    $settlement = CommissionSettlementStatus::tryFrom((string) ($payment['commission_status'] ?? ''))
                        ?? CommissionSettlementStatus::PendingSettlement;

                    DB::table('rental_commissions')->insert([
                        'id' => $commissionId,
                        'organization_id' => $orgId,
                        'boat_booking_id' => (int) $row['id'],
                        'operator_id' => $boat?->operator?->id,
                        'rental_amount' => $payment['rental_price'] ?? '0.00',
                        'commission_percentage' => $percent ?? '0.00',
                        'commission_amount' => $this->moneyRaw($payment['commission_amount_label'] ?? null) ?? '0.00',
                        'operator_net_amount' => $this->moneyRaw($payment['operator_net_label'] ?? null) ?? '0.00',
                        'settlement_status' => $settlement->value,
                        'gcash_reference' => $payment['gcash_reference'] ?? null,
                        'verified_by' => $settlement->isReceived() && $admin ? $admin->id : null,
                        'verified_at' => $settlement->isReceived() ? ($verifiedAt ?? $createdAt) : null,
                        'created_at' => $createdAt,
                        'updated_at' => $verifiedAt ?? $createdAt,
                    ]);
                    $commissionCount++;
                }
            }
        });

        $this->fixAutoIncrement('boat_bookings');
        $this->fixAutoIncrement('rental_commissions');

        OrganizationCommissionSetting::query()->updateOrCreate(
            ['organization_id' => $orgId],
            [
                'commission_enabled' => true,
                'commission_percentage' => '2.00',
                'effective_date' => now()->subMonth()->toDateString(),
            ],
        );

        $announcementCount = $this->restoreAnnouncementsAndWeather($orgId, $admin?->id);

        $this->command?->info("Restored {$bookingCount} rentals, {$commissionCount} commissions, {$activityCount} activities, {$announcementCount} announcements.");
    }

    private function restoreAnnouncementsAndWeather(int $orgId, ?int $adminId): int
    {
        $props = $this->loadSnapshotProps(self::ANNOUNCEMENTS_SNAPSHOT);
        $rows = $props['announcements'] ?? [];
        $weatherRows = $props['weatherSuspensions'] ?? [];

        WeatherSuspension::query()->where('organization_id', $orgId)->delete();
        Announcement::query()->where('organization_id', $orgId)->delete();

        $count = 0;
        $announcementIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $publishedAt = $this->parseDateTime((string) ($row['published_at'] ?? '')) ?? now();
            $id = (int) ($row['id'] ?? 0);

            $payload = [
                'organization_id' => $orgId,
                'user_id' => $adminId,
                'title' => $row['title'],
                'body' => $row['body'],
                'category' => $row['category'] ?? 'general',
                'audience' => json_encode($row['audience'] ?? ['operator', 'passenger']),
                'recipient_count' => (int) ($row['recipient_count'] ?? 0),
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ];

            if ($id > 0) {
                $payload['id'] = $id;
            }

            DB::table('announcements')->insert($payload);

            if ($id > 0) {
                $announcementIds[$id] = true;
            }

            $count++;
        }

        $this->fixAutoIncrement('announcements');

        foreach ($weatherRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $reason = WeatherHoldReason::tryFrom((string) ($row['reason'] ?? 'bad_weather')) ?? WeatherHoldReason::BadWeather;
            $status = WeatherSuspensionStatus::tryFrom((string) ($row['status'] ?? 'active')) ?? WeatherSuspensionStatus::Active;
            $announcementId = (int) ($row['announcement_id'] ?? 0);

            WeatherSuspension::query()->create([
                'organization_id' => $orgId,
                'declared_by' => $adminId ?? 1,
                'announcement_id' => isset($announcementIds[$announcementId]) ? $announcementId : null,
                'code' => $row['code'] ?? WeatherSuspension::nextCode(),
                'reason' => $reason,
                'affected_date' => $row['affected_date'] ?? now()->toDateString(),
                'affected_routes' => $row['affected_routes'] ?? ['all'],
                'duration_type' => $row['duration_type'] ?? 'whole_day',
                'starts_at' => $row['starts_at'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
                'announcement_message' => $row['announcement_message'] ?? '',
                'status' => $status,
                'affected_trips_count' => (int) ($row['affected_trips_count'] ?? 0),
                'affected_rentals_count' => (int) ($row['affected_rentals_count'] ?? 0),
                'review_flagged_count' => (int) ($row['review_flagged_count'] ?? 0),
            ]);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    private function activityTime(array $booking, string $event): ?Carbon
    {
        foreach ($booking['activities'] ?? [] as $activity) {
            if (! is_array($activity)) {
                continue;
            }

            if (($activity['event'] ?? '') === $event) {
                return $this->parseDateTime((string) ($activity['occurred_at'] ?? ''));
            }
        }

        return null;
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

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }

        return $time !== '' ? $time : '00:00:00';
    }

    private function decimalFromPercentLabel(string $label): ?string
    {
        if ($label === '') {
            return null;
        }

        $numeric = (float) str_replace(['%', ','], '', $label);

        return number_format($numeric, 2, '.', '');
    }

    private function moneyRaw(mixed $label): ?string
    {
        if (! is_string($label) || $label === '') {
            return null;
        }

        $numeric = (float) preg_replace('/[^\d.]/', '', $label);

        return number_format($numeric, 2, '.', '');
    }

    private function fixAutoIncrement(string $table): void
    {
        $max = (int) DB::table($table)->max('id');

        if ($max > 0) {
            DB::statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.($max + 1));
        }
    }
}
