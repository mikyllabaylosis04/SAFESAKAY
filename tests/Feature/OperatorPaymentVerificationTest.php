<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\OperatorGcashStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OperatorGcashAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperatorPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_verifies_down_payment_and_operator_preserves_pricing(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
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
        $this->assertNotNull($booking->payment_verified_at);
        $this->assertSame('1500.00', $booking->rental_price);
        $this->assertSame('450.00', $booking->down_payment_amount);
        $this->assertSame('1050.00', $booking->remaining_balance);
        $this->assertSame(GcashPayee::Barangay, $booking->gcashPayee());
        $this->assertTrue($passenger->notifications()->where('data->event', 'approved')->exists());
    }

    public function test_receipt_upload_does_not_confirm_the_rental(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame(BookingPaymentStatus::ForVerification, $booking->paymentStatus());
    }

    public function test_duplicate_verification_is_rejected(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);
        $this->verifyDownPaymentAsAdmin($admin, $booking);

        $this->actingAs($admin)
            ->post(route('boat-bookings.verify-payment', $booking), [
                'confirmed_gcash_transaction' => '1',
            ])
            ->assertSessionHasErrors();
    }

    public function test_operator_cannot_verify_down_payment_via_api(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
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
    }

    public function test_admin_cannot_verify_down_payment_via_mobile_api(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/verify-payment", [
                'confirmed_gcash_transaction' => true,
            ])
            ->assertForbidden();
    }

    public function test_operator_cannot_reject_barangay_down_payment(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/reject-payment", [
                'reason' => 'Reference number does not match GCash history.',
            ])
            ->assertForbidden();
    }

    public function test_admin_rejects_proof_and_passenger_can_resubmit(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($admin)
            ->post(route('boat-bookings.reject-payment', $booking), [
                'reason' => 'Amount does not match the GCash transaction.',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame(BookingPaymentStatus::Rejected, $booking->paymentStatus());
        $this->assertTrue($passenger->notifications()->where('data->event', 'payment_rejected')->exists());

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASHRESUBMIT1',
                'payment_amount' => '450',
                'receipt' => UploadedFile::fake()->image('receipt-2.jpg', 600, 800),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'for_verification')
            ->assertJsonPath('data.status', 'awaiting_payment');
    }

    public function test_operator_records_balance_then_completes_trip(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);
        $this->verifyDownPaymentAsAdmin($admin, $booking);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/balance-payment", [
                'method' => 'gcash',
                'reference' => 'GCASHBALANCE99',
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.balance_status', 'paid')
            ->assertJsonPath('data.balance_method', 'gcash')
            ->assertJsonPath('data.can_complete', true)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.remaining_balance', 1050);

        $booking->refresh();
        $this->assertSame(BookingBalanceStatus::Paid, $booking->balanceStatus());
        $this->assertSame($operator->id, $booking->balance_recorded_by);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/balance-payment", [
                'method' => 'cash',
                'confirmed' => true,
            ])
            ->assertStatus(422);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Completed, $booking->status);
        $this->assertNotNull($booking->completed_at);
        $this->assertTrue($passenger->notifications()->where('data->event', 'completed')->exists());
    }

    public function test_passenger_sees_barangay_gcash_for_down_and_operator_for_balance(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $admin->organization?->update([
            'gcash_account_name' => 'Official Barangay GCash',
            'gcash_qr_path' => 'gcash-qr/barangay.png',
        ]);

        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.gcash_payee', GcashPayee::Barangay->value)
            ->assertJsonPath('data.gcash_account_name', 'Official Barangay GCash')
            ->assertJsonPath('data.balance_gcash_account_name', 'Juan Operator GCash')
            ->assertJsonPath('data.balance_gcash_mobile_number', '09171234567')
            ->assertJsonPath('data.can_verify_payment', false);
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User, 3: User}
     */
    private function linkedRental(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $admin->organization?->update([
            'gcash_account_name' => 'Barangay Admin GCash',
            'gcash_qr_path' => 'gcash-qr/admin.png',
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

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-00088',
            'customer_name' => 'Maria Santos',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-12',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '1500.00',
            'down_payment_percent' => '30.00',
            'down_payment_amount' => '450.00',
            'remaining_balance' => '1050.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
        ]);

        return [$operator, $booking, $passenger, $admin];
    }

    private function submitProof(User $operator, User $passenger, BoatBooking $booking): void
    {
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASHDOWN450',
                'payment_amount' => '450',
                'receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
            ])
            ->assertOk();
    }

    private function verifyDownPaymentAsAdmin(User $admin, BoatBooking $booking): void
    {
        $this->actingAs($admin)
            ->post(route('boat-bookings.verify-payment', $booking), [
                'confirmed_gcash_transaction' => '1',
            ])
            ->assertRedirect();
    }

    public function test_verify_requires_gcash_history_confirmation(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $this->actingAs($admin)
            ->post(route('boat-bookings.verify-payment', $booking), [])
            ->assertSessionHasErrors(['confirmed_gcash_transaction']);
    }

    public function test_duplicate_gcash_reference_across_bookings_is_rejected(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->submitProof($operator, $passenger, $booking);

        $second = BoatBooking::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_id' => $booking->boat_id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-DUPREF',
            'customer_name' => 'Maria Santos',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-13',
            'start_time' => '10:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '1500.00',
            'down_payment_percent' => '30.00',
            'down_payment_amount' => '450.00',
            'remaining_balance' => '1050.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
        ]);

        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$second->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$second->id}/payment-proof", [
                'gcash_reference' => 'GCASHDOWN450',
                'payment_amount' => '450',
                'receipt' => UploadedFile::fake()->image('dup.jpg', 600, 800),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gcash_reference']);
    }
}
