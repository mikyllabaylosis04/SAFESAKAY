<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualGcashPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_gcash_account_and_qr(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->post(route('settings.payments.update'), [
                'gcash_account_name' => 'Barangay Lakandula GCash',
                'gcash_qr' => UploadedFile::fake()->image('qr.png', 400, 400),
            ])
            ->assertRedirect(route('settings.payments.edit'));

        $admin->organization?->refresh();

        $this->assertSame('Barangay Lakandula GCash', $admin->organization?->gcash_account_name);
        $this->assertNotNull($admin->organization?->gcash_qr_path);
        Storage::disk('public')->assertExists($admin->organization?->gcash_qr_path);
    }

    public function test_operator_accept_sets_awaiting_payment_without_confirming(): void
    {
        [$operator, $booking] = $this->linkedRental();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.status_raw', BoatBookingStatus::AwaitingPayment->value)
            ->assertJsonPath('data.can_pay', true)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.rental_price', 4500)
            ->assertJsonPath('data.down_payment_amount', 900);

        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->fresh()->status);
        $this->assertSame(BookingPaymentStatus::Unpaid, $booking->fresh()->paymentStatus());
    }

    public function test_passenger_can_submit_payment_proof_for_verification_only(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASH998877',
                'payment_amount' => '900',
                'receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment_status', 'for_verification')
            ->assertJsonPath('data.payment_status_label', 'For Verification')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.payment_submitted', true)
            ->assertJsonPath('data.gcash_reference', 'GCASH998877')
            ->assertJsonPath('data.rental_price', 4500)
            ->assertJsonPath('data.down_payment_amount', 900)
            ->assertJsonPath('data.remaining_balance', 3600);

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame(BookingPaymentStatus::ForVerification, $booking->paymentStatus());
        $this->assertNotNull($booking->payment_receipt_path);
        Storage::disk('local')->assertExists($booking->payment_receipt_path);
    }

    public function test_payment_proof_defaults_amount_to_required_down_payment(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASHLEGACY01',
                'receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_amount_submitted', 900);

        $this->assertSame('900.00', $booking->fresh()->payment_amount_submitted);
    }

    public function test_duplicate_payment_proof_is_rejected(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $payload = [
            'gcash_reference' => 'GCASH111222',
            'payment_amount' => '900',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ];

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", $payload)
            ->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASH333444',
                'payment_amount' => '900',
                'receipt' => UploadedFile::fake()->image('receipt-2.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_other_passenger_cannot_submit_payment_proof(): void
    {
        Storage::fake('local');
        [$operator, $booking] = $this->linkedRental();
        $stranger = User::factory()->create([
            'organization_id' => $booking->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($stranger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASH555666',
                'payment_amount' => '900',
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_payments_settings_page_is_displayed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get(route('settings.payments.edit'))
            ->assertRedirect('/settings/commission');

        $this->actingAs($admin)
            ->get(route('settings.commission.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Commission')
                ->has('commission')
                ->has('can_manage')
                ->missing('platform_plan'));
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User}
     */
    private function linkedRental(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $admin->organization?->update([
            'gcash_account_name' => 'Lakandula Treasurer',
            'gcash_qr_path' => 'gcash-qr/demo.png',
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
            'code' => 'BR-2026-00021',
            'customer_name' => 'Maria Santos',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-12',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '4500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '900.00',
            'remaining_balance' => '3600.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
        ]);

        return [$operator, $booking, $passenger];
    }
}
