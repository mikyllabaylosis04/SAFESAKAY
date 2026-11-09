<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementStatus;
use App\Enums\GcashPayee;
use App\Enums\OperatorPayoutStatus;
use App\Enums\OperatorGcashStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OperatorGcashAccount;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Models\User;
use App\Services\OperatorGcashPayoutService;
use App\Services\RentalCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RentalCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_save_exclusive_rental_commission_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->post(route('settings.payments.commission.update'), [
                'commission_enabled' => true,
                'commission_percentage' => 10,
                'settlement_due_days' => 3,
            ])
            ->assertRedirect(route('settings.commission.edit'));

        $setting = OrganizationCommissionSetting::query()
            ->where('organization_id', $admin->organization_id)
            ->first();

        $this->assertTrue($setting?->commission_enabled);
        $this->assertEquals(10, (float) $setting?->commission_percentage);
        $this->assertNotNull($setting?->effective_date);
    }

    public function test_commission_saves_without_deadline_and_keeps_date_when_unchanged(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->actingAs($admin)
            ->post(route('settings.payments.commission.update'), [
                'commission_enabled' => true,
                'commission_percentage' => 5,
            ])
            ->assertSessionHasNoErrors();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $this->actingAs($admin)
            ->post(route('settings.payments.commission.update'), [
                'commission_enabled' => true,
                'commission_percentage' => 5,
            ])
            ->assertSessionHasNoErrors();

        $setting = OrganizationCommissionSetting::query()
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();

        $this->assertSame('2026-10-01', $setting->effective_date->toDateString());
        $this->assertSame(3, (int) $setting->settlement_due_days);

        $this->actingAs($admin)
            ->post(route('settings.payments.commission.update'), [
                'commission_enabled' => true,
                'commission_percentage' => 7,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-09', $setting->fresh()->effective_date->toDateString());
    }

    public function test_staff_cannot_update_commission_settings(): void
    {
        $staff = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($staff)
            ->post(route('settings.payments.commission.update'), [
                'commission_enabled' => true,
                'commission_percentage' => 10,
                'settlement_due_days' => 3,
            ])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_open_barangay_commission_pages(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)
            ->get(route('operator-payouts.index'))
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_passenger_booking_snapshots_included_commission_and_operator_net(): void
    {
        [$admin, $operator, $passenger, $booking] = $this->exclusiveRental(enabled: true, complete: false);

        $this->assertTrue($booking->commission_enabled);
        $this->assertSame('200.00', number_format((float) $booking->commission_amount, 2, '.', ''));
        $this->assertSame('800.00', number_format((float) $booking->operator_net_amount, 2, '.', ''));

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/bookings/'.$booking->id)
            ->assertOk()
            ->assertJsonPath('data.commission.enabled', true)
            ->assertJsonPath('data.commission.included_in_total', true)
            ->assertJsonPath('data.commission.amount', 200)
            ->assertJsonPath('data.commission.operator_net', 800)
            ->assertJsonPath('data.overall_payment_status', 'awaiting_payment');
    }

    public function test_verified_payment_creates_commission_before_the_trip_is_completed(): void
    {
        [, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: false, verified: true);

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->status);
        $this->assertDatabaseCount('rental_commissions', 1);

        $commission = RentalCommission::query()->first();
        $this->assertSame($booking->id, $commission?->boat_booking_id);
        $this->assertSame(CommissionSettlementStatus::CollectedWithRental, $commission?->settlement_status);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'collected_with_rental');
    }

    public function test_booking_created_with_verified_downpayment_generates_commission_immediately(): void
    {
        [$admin, $operator, ,] = $this->exclusiveRental(enabled: true, complete: false, verified: false);
        $boat = Boat::query()->where('organization_id', $admin->organization_id)->firstOrFail();

        $booking = BoatBooking::createWithUniqueCode([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => null,
            'customer_name' => 'Walk-in Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Confirmed,
            'rental_price' => '1000.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '200.00',
            'remaining_balance' => '800.00',
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'payment_verified_by' => $admin->id,
            'gcash_payee' => GcashPayee::Barangay,
            'commission_enabled' => true,
            'commission_percentage' => '2.00',
            'commission_amount' => '4.00',
            'operator_net_amount' => '196.00',
            'remarks' => "@walk-in:cash;received=1000.00;change=800.00",
        ]);

        $this->assertDatabaseHas('rental_commissions', [
            'boat_booking_id' => $booking->id,
            'settlement_status' => CommissionSettlementStatus::CollectedWithRental->value,
            'commission_amount' => '20.00',
            'operator_net_amount' => '180.00',
            'verified_downpayment' => '200.00',
        ]);
    }

    public function test_completed_fully_paid_exclusive_rental_creates_one_pending_settlement(): void
    {
        [, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: true);

        $this->assertDatabaseCount('rental_commissions', 1);

        $commission = RentalCommission::query()->first();
        $this->assertSame($booking->id, $commission?->boat_booking_id);
        $this->assertSame(CommissionSettlementStatus::CollectedWithRental, $commission?->settlement_status);
        $this->assertSame('200.00', number_format((float) $commission?->commission_amount, 2, '.', ''));

        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());
        $this->assertDatabaseCount('rental_commissions', 1);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.code', $booking->code)
            ->assertJsonPath('data.0.status', 'collected_with_rental')
            ->assertJsonPath('data.0.commission_amount', 200);
    }

    public function test_disabled_or_regular_style_booking_does_not_create_commission(): void
    {
        $this->exclusiveRental(enabled: false, complete: true);

        $this->assertDatabaseCount('rental_commissions', 0);
    }

    public function test_operator_settlement_awaits_admin_verification_not_auto_paid(): void
    {
        Storage::fake('public');
        [$admin, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: true, downPaymentPayee: GcashPayee::Operator);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/commissions/'.$commission->id.'/settle', [
                'gcash_reference' => 'GCASH-COMM-200',
                'receipt' => UploadedFile::fake()->image('settlement.jpg', 400, 400),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_verification')
            ->assertJsonPath('data.gcash_reference', 'GCASH-COMM-200');

        $this->assertSame(CommissionSettlementStatus::AwaitingVerification, $commission->fresh()->settlement_status);
        $this->assertNotSame(CommissionSettlementStatus::Paid, $commission->fresh()->settlement_status);

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commissions/Index')
                ->where('commissions.0.status', 'awaiting_verification'));

        $this->actingAs($admin)
            ->post(route('commissions.verify', $commission))
            ->assertRedirect();

        $this->assertSame(CommissionSettlementStatus::Paid, $commission->fresh()->settlement_status);
        $this->assertSame($admin->id, $commission->fresh()->verified_by);
    }

    public function test_rejected_settlement_can_be_resubmitted(): void
    {
        Storage::fake('public');
        [$admin, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: true, downPaymentPayee: GcashPayee::Operator);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/commissions/'.$commission->id.'/settle', [
                'gcash_reference' => 'WRONG-REF-1',
                'receipt' => UploadedFile::fake()->image('bad.jpg'),
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('commissions.reject', $commission), [
                'reason' => 'Reference does not match barangay GCash.',
            ])
            ->assertRedirect();

        $this->assertSame(CommissionSettlementStatus::PendingSettlement, $commission->fresh()->settlement_status);
        $this->assertSame('Reference does not match barangay GCash.', $commission->fresh()->rejection_reason);

        $this->actingAs($operator, 'sanctum')
            ->post('/api/commissions/'.$commission->id.'/settle', [
                'gcash_reference' => 'CORRECT-REF-99',
                'receipt' => UploadedFile::fake()->image('good.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_verification');
    }

    public function test_commission_records_are_isolated_by_organization(): void
    {
        [, , , $booking] = $this->exclusiveRental(enabled: true, complete: true);
        $outsider = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($outsider)
            ->get(route('operator-payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions', []));

        $this->assertNotSame($booking->organization_id, $outsider->organization_id);
    }

    public function test_weather_hold_keeps_operator_payout_on_hold(): void
    {
        [$admin, , , $booking] = $this->exclusiveRental(enabled: true, complete: false, verified: true);
        $booking->update(['status' => BoatBookingStatus::WeatherHold]);
        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());

        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        app(RentalCommissionService::class)->reconcileOperatorPayoutStatus($commission->fresh());

        $this->assertSame(OperatorPayoutStatus::OnHold, $commission->fresh()->operator_payout_status);

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions.0.payout_status', 'on_hold'));
    }

    public function test_admin_records_operator_payout_after_completed_trip(): void
    {
        [$admin, , , $booking] = $this->exclusiveRental(enabled: true, complete: true);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->assertSame(OperatorPayoutStatus::ReadyForPayout, $commission->operator_payout_status);

        $this->actingAs($admin)
            ->post(route('commissions.record-payout', $commission), [
                'remarks' => 'Released at barangay desk',
                'confirm_payout' => true,
            ])
            ->assertRedirect();

        $fresh = $commission->fresh();
        $this->assertSame(OperatorPayoutStatus::PaidOut, $fresh->operator_payout_status);
        $this->assertSame('800.00', number_format((float) $fresh->operator_net_amount, 2, '.', ''));
        $this->assertNotNull($fresh->operator_payout_reference);
        $this->assertStringStartsWith('OP-', (string) $fresh->operator_payout_reference);
    }

    public function test_completed_trip_pays_operator_gcash_after_commission(): void
    {
        Config::set('services.xendit.enabled', true);
        Config::set('services.xendit.secret_key', 'xnd_development_test_key');
        Config::set('services.xendit.operator_payouts', true);

        Http::fake([
            'https://api.xendit.co/v2/payouts' => Http::response([
                'id' => 'disb-test-1',
                'status' => 'SUCCEEDED',
                'currency' => 'PHP',
            ], 200),
        ]);

        [, , , $booking] = $this->exclusiveRental(enabled: true, complete: true);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $net = round((float) $commission->operator_net_amount, 2);

        app(OperatorGcashPayoutService::class)->releaseIfReady($commission->fresh());

        $fresh = $commission->fresh();
        $this->assertSame(OperatorPayoutStatus::PaidOut, $fresh->operator_payout_status);
        $this->assertSame('gcash', $fresh->operator_payout_method?->value);
        $this->assertSame('xendit:disb-test-1', $fresh->operator_payout_reference);
        $this->assertSame('800.00', number_format((float) $fresh->operator_net_amount, 2, '.', ''));

        Http::assertSent(function ($request) use ($net): bool {
            $body = $request->data();

            return $request->url() === 'https://api.xendit.co/v2/payouts'
                && ($body['channel_code'] ?? null) === 'PH_GCASH'
                && ($body['currency'] ?? null) === 'PHP'
                && ($body['amount'] ?? null) === $net
                && ($body['channel_properties']['account_number'] ?? null) === '09171234567'
                && ($body['channel_properties']['account_holder_name'] ?? null) === 'Juan Operator GCash';
        });
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: BoatBooking}
     */
    protected function exclusiveRental(
        bool $enabled,
        bool $complete,
        bool $verified = false,
        GcashPayee $downPaymentPayee = GcashPayee::Barangay,
    ): array {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $admin->organization?->update([
            'gcash_account_name' => 'Barangay Lakandula GCash',
            'gcash_qr_path' => 'gcash-qr/demo.png',
        ]);

        if ($enabled) {
            OrganizationCommissionSetting::query()->create([
                'organization_id' => $admin->organization_id,
                'commission_enabled' => true,
                'commission_percentage' => '10.00',
                'effective_date' => now()->toDateString(),
            ]);
        }

        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);
        OperatorGcashAccount::query()->create([
            'operator_id' => $boat->operator?->id,
            'organization_id' => $admin->organization_id,
            'account_name' => 'Juan Operator GCash',
            'mobile_number' => '09171234567',
            'qr_path' => 'operator-gcash/demo.png',
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        $snapshot = app(RentalCommissionService::class)->snapshotForBooking(
            $admin->organization_id,
            2000,
            1000,
        );

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-001',
            'customer_name' => $passenger->name,
            'package' => 'Private island hopping',
            'destination' => 'Private island hopping',
            'booking_date' => '2026-10-20',
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '2000.00',
            'down_payment_percent' => '50.00',
            'down_payment_amount' => '1000.00',
            'remaining_balance' => $complete ? '0.00' : '1000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => $downPaymentPayee,
            ...$snapshot,
        ]);

        if ($verified || $complete) {
            $booking->update([
                'status' => $complete ? BoatBookingStatus::Ongoing : BoatBookingStatus::Confirmed,
                'payment_status' => BookingPaymentStatus::Verified,
                'payment_verified_at' => now(),
            ]);
        }

        if ($complete) {
            $booking->update([
                'status' => BoatBookingStatus::Completed,
                'completed_at' => now(),
            ]);
        }

        return [$admin, $operator, $passenger, $booking->fresh()];
    }
}
