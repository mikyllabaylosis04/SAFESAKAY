<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\OperatorGcashStatus;
use App\Enums\OperatorPayoutStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OperatorGcashAccount;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\RentalCommissionService;
use App\Support\RentalPaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_sees_verified_earnings_without_counting_unpaid_bookings(): void
    {
        [$operator, $verified, $awaiting] = $this->rentals();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions?period=all')
            ->assertOk()
            ->assertJsonPath('data.summary.verified_payments', 1000)
            ->assertJsonPath('data.summary.commission_deducted', 200)
            ->assertJsonPath('data.summary.net_earnings', 800)
            ->assertJsonPath('data.summary.payouts_received', null)
            ->assertJsonFragment([
                'booking_reference' => $verified->code,
                'payment_type' => 'downpayment',
                'payment_status' => 'verified',
                'payout_label' => 'On Hold',
            ])
            ->assertJsonFragment([
                'booking_reference' => $awaiting->code,
                'payment_status' => 'awaiting',
            ]);
    }

    public function test_balance_payment_keeps_the_downpayment_and_uses_recorded_commission_once(): void
    {
        [$operator, $verified] = $this->rentals();

        RentalPayment::query()->create([
            'organization_id' => $verified->organization_id,
            'boat_booking_id' => $verified->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 100000,
            'commission_amount' => 10000,
            'operator_amount' => 90000,
            'currency' => 'PHP',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $verified->update([
            'remaining_balance' => '0.00',
            'balance_status' => BookingBalanceStatus::Paid,
            'balance_method' => 'gcash',
            'balance_reference' => 'BAL-100',
            'balance_paid_at' => now(),
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions/'.$verified->id)
            ->assertOk();

        $payments = collect($response->json('data.payments'));
        $this->assertTrue($payments->contains(fn (array $row) => $row['payment_type'] === 'downpayment' && $row['transaction_id'] !== 'BAL-100'));
        $this->assertTrue($payments->contains(fn (array $row) => $row['payment_type'] === 'balance' && $row['transaction_id'] === 'BAL-100'));
        $this->assertSame(1, $payments->where('carries_commission', true)->count());
        $response->assertJsonPath('data.earnings.verified_paid', 2000)
            ->assertJsonPath('data.earnings.commission_deducted', 200)
            ->assertJsonPath('data.earnings.operator_net', 1800);
    }

    public function test_another_operator_cannot_open_the_transaction(): void
    {
        [, $verified] = $this->rentals();
        $other = User::factory()->create([
            'organization_id' => $verified->organization_id,
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/operator/transactions/'.$verified->id)
            ->assertNotFound();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/operator/transactions?period=all')
            ->assertOk()
            ->assertJsonPath('data.summary.verified_payments', 0);
    }

    public function test_paid_out_is_separate_from_net_earnings(): void
    {
        [$operator, $verified] = $this->rentals();
        $verified->update([
            'status' => BoatBookingStatus::Completed,
            'completed_at' => now(),
        ]);
        $verified->commission?->update([
            'operator_payout_status' => OperatorPayoutStatus::PaidOut,
            'operator_payout_at' => now(),
            'operator_payout_reference' => 'OP-100',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions?period=all')
            ->assertOk()
            ->assertJsonPath('data.summary.net_earnings', 800)
            ->assertJsonPath('data.summary.payouts_received', 800)
            ->assertJsonFragment(['payout_label' => 'Paid Out']);
    }

    public function test_receipt_is_only_offered_for_a_verified_payment(): void
    {
        [$operator, $verified, $awaiting] = $this->rentals();
        $verified->update(['payment_receipt_path' => 'payment-receipts/verified.png']);
        $awaiting->update([
            'payment_receipt_path' => 'payment-receipts/pending.png',
            'payment_status' => BookingPaymentStatus::ForVerification,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions/'.$verified->id)
            ->assertOk()
            ->assertJsonPath('data.receipt_available', true)
            ->assertJsonPath('data.receipt_url', '/api/bookings/'.$verified->id.'/receipt');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions/'.$awaiting->id)
            ->assertOk()
            ->assertJsonPath('data.receipt_available', false)
            ->assertJsonPath('data.receipt_url', null);
    }

    public function test_summary_reports_processing_payout_without_claiming_paid_out(): void
    {
        [$operator, $verified] = $this->rentals();
        $verified->commission?->update([
            'operator_payout_status' => OperatorPayoutStatus::ReadyForPayout,
            'operator_payout_reference' => 'xendit:disb-123',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/transactions?period=all')
            ->assertOk()
            ->assertJsonPath('data.summary.processing_payout_count', 1)
            ->assertJsonPath('data.summary.payouts_received_count', 0)
            ->assertJsonPath('data.summary.payouts_received', null)
            ->assertJsonFragment(['payout_label' => 'Processing']);
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: BoatBooking}
     */
    public function test_retried_checkout_attempts_are_not_listed_as_failed_payments(): void
    {
        [, $verified, $awaiting] = $this->rentals();

        $attempt = fn (BoatBooking $booking, string $status, string $createdAt) => RentalPayment::query()->forceCreate([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 100000,
            'currency' => 'PHP',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $attempt($verified, 'expired', '2026-11-06 01:00:00');
        $attempt($verified, 'expired', '2026-11-06 02:00:00');
        $attempt($verified, 'paid', '2026-11-06 03:00:00');
        $attempt($awaiting, 'expired', '2026-11-07 01:00:00');
        $attempt($awaiting, 'expired', '2026-11-07 03:39:24');

        $ledger = app(RentalPaymentLedger::class);

        $paidLines = collect($ledger->lines($verified->fresh(['rentalPayments', 'commission'])));
        $this->assertFalse($paidLines->contains('payment_status', 'failed'));
        $this->assertSame(1, $paidLines->where('payment_status', 'verified')->where('id', '!=', 'verified-'.$verified->id)->count());

        $unpaidFailed = collect($ledger->lines($awaiting->fresh(['rentalPayments', 'commission'])))->where('payment_status', 'failed');
        $this->assertCount(1, $unpaidFailed);
        $this->assertNotNull($unpaidFailed->first()['paid_at']);
        $this->assertStringStartsWith('2026-11-07T03:39:24', (string) $unpaidFailed->first()['paid_at']);
    }

    public function test_full_rental_payment_is_not_split_into_an_extra_balance_row(): void
    {
        [, , $booking] = $this->rentals();

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'full_rental',
            'amount' => 200000,
            'currency' => 'PHP',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $booking->update([
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'remaining_balance' => '0.00',
            'balance_status' => BookingBalanceStatus::Paid,
            'balance_paid_at' => now(),
        ]);

        $lines = collect(app(RentalPaymentLedger::class)->lines($booking->fresh(['rentalPayments', 'commission'])));

        $this->assertCount(1, $lines);
        $this->assertSame('full', $lines->first()['payment_type']);
        $this->assertSame(2000.0, $lines->first()['amount_paid']);
    }

    private function rentals(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '10.00',
            'effective_date' => now()->toDateString(),
        ]);

        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'name' => 'Boat Operator',
        ]);
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Passenger',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Lady Lakandula',
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id, 'full_name' => 'Boat Operator']);
        OperatorGcashAccount::query()->create([
            'operator_id' => $boat->operator?->id,
            'organization_id' => $admin->organization_id,
            'account_name' => 'Boat Operator',
            'mobile_number' => '09171234567',
            'qr_path' => 'operator-gcash/demo.png',
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        $snapshot = app(RentalCommissionService::class)->snapshotForBooking($admin->organization_id, 2000, 1000);
        $verified = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-VERIFIED',
            'customer_name' => 'Maria Passenger',
            'contact_number' => '09180001111',
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'pickup_point' => 'Lakandula port',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2000.00',
            'down_payment_percent' => '50.00',
            'down_payment_amount' => '1000.00',
            'remaining_balance' => '1000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
            ...$snapshot,
        ]);
        $verified->update([
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'gcash_reference' => 'GC-100',
        ]);

        $awaiting = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-WAITING',
            'customer_name' => 'Pedro Waiting',
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->toDateString(),
            'start_time' => '13:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2000.00',
            'down_payment_percent' => '50.00',
            'down_payment_amount' => '1000.00',
            'remaining_balance' => '1000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
            ...$snapshot,
        ]);

        return [$operator, $verified->fresh(['commission']), $awaiting->fresh()];
    }
}
