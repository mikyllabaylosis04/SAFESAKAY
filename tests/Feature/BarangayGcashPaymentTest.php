<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementStatus;
use App\Enums\GcashPayee;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BarangayGcashPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_pays_barangay_gcash_when_operator_has_none(): void
    {
        [$admin, $operator, $passenger, $booking] = $this->rentalWithoutOperatorGcash();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/approve")
            ->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.gcash_payee', GcashPayee::Barangay->value)
            ->assertJsonPath('data.gcash_account_name', 'Barangay Lakandula GCash')
            ->assertJsonPath('data.can_verify_payment', false)
            ->assertJsonPath('data.commission.enabled', true);
    }

    public function test_operator_cannot_verify_when_money_goes_to_barangay_gcash(): void
    {
        Storage::fake('local');
        [$admin, $operator, $passenger, $booking] = $this->rentalWithoutOperatorGcash();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/verify-payment", [
                'confirmed_gcash_transaction' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You cannot verify this payment. The passenger paid the barangay GCash account. Only a barangay administrator can verify it in the admin portal.',
            );

        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.can_verify_payment', false)
            ->assertJsonPath(
                'data.verify_payment_blocked_reason',
                'You cannot verify this payment. The passenger paid the barangay GCash account. Only a barangay administrator can verify it in the admin portal.',
            );
    }

    public function test_admin_verifies_barangay_gcash_down_payment_and_monitors_balance(): void
    {
        Storage::fake('local');
        [$admin, $operator, $passenger, $booking] = $this->rentalWithoutOperatorGcash();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($admin)
            ->post(route('boat-bookings.verify-payment', $booking), [
                'confirmed_gcash_transaction' => '1',
            ])
            ->assertRedirect(route('bookings.requests.show', $booking));

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->status);
        $this->assertSame(BookingPaymentStatus::Verified, $booking->paymentStatus());
        $this->assertSame($admin->id, $booking->payment_verified_by);
        $this->assertSame(GcashPayee::Barangay, $booking->gcashPayee());
        $this->assertDatabaseHas('rental_commissions', [
            'boat_booking_id' => $booking->id,
            'settlement_status' => CommissionSettlementStatus::CollectedWithRental->value,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/balance-payment", [
                'method' => 'gcash',
                'reference' => 'GCASHOPBAL1',
                'confirmed' => true,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame(BookingBalanceStatus::Paid, $booking->balanceStatus());
        $this->assertSame($operator->id, $booking->balance_recorded_by);

        $this->actingAs($admin)
            ->post(route('boat-bookings.balance-payment', $booking), [
                'method' => 'gcash',
                'reference' => 'GCASHADMINBAL1',
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseCount('rental_commissions', 1);

        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->first();
        $this->assertSame($admin->id, $commission?->verified_by);
        $this->assertFalse($commission?->canSubmitSettlement());
    }

    public function test_staff_cannot_verify_barangay_gcash_payment(): void
    {
        Storage::fake('local');
        [$admin, $operator, $passenger, $booking] = $this->rentalWithoutOperatorGcash();
        $staff = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::User,
        ]);
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($staff)
            ->post(route('boat-bookings.verify-payment', $booking))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: BoatBooking}
     */
    private function rentalWithoutOperatorGcash(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $admin->organization?->update([
            'gcash_account_name' => 'Barangay Lakandula GCash',
            'gcash_qr_path' => 'gcash-qr/demo.png',
        ]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '10.00',
            'effective_date' => now()->toDateString(),
        ]);

        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Santos',
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

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-BARANGAY',
            'customer_name' => 'Maria Santos',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-10-21',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '2000.00',
            'down_payment_percent' => '30.00',
            'down_payment_amount' => '600.00',
            'remaining_balance' => '1400.00',
            'commission_enabled' => true,
            'commission_percentage' => '10.00',
            'commission_amount' => '200.00',
            'operator_net_amount' => '1800.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
        ]);

        return [$admin, $operator, $passenger, $booking->fresh()];
    }

    private function submitProof(User $operator, User $passenger, BoatBooking $booking): void
    {
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASHDOWN600',
                'payment_amount' => '600',
                'receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
            ])
            ->assertOk();
    }
}
