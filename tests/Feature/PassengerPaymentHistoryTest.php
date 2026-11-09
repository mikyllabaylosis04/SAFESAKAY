<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassengerPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_sees_verified_downpayment_and_balance_due(): void
    {
        [$passenger, $booking] = $this->rental();

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/payments/history')
            ->assertOk()
            ->assertJsonPath('data.summary.total_paid', 1000)
            ->assertJsonPath('data.summary.balance_due', 1000)
            ->assertJsonPath('data.summary.balance_due_count', 1)
            ->assertJsonPath('data.summary.pending_count', 0)
            ->assertJsonFragment([
                'booking_reference' => $booking->code,
                'payment_type' => 'downpayment',
                'payment_status' => 'verified',
                'transaction_id' => 'GC-100',
                'boat_name' => 'Lady Lakandula',
            ])
            ->assertJsonMissingPath('data.payments.0.commission_deducted')
            ->assertJsonMissingPath('data.payments.0.operator_share');
    }

    public function test_settled_balance_shows_as_a_second_payment_and_clears_balance_due(): void
    {
        [$passenger, $booking] = $this->rental();
        $booking->update([
            'remaining_balance' => '0.00',
            'balance_status' => BookingBalanceStatus::Paid,
            'balance_method' => 'cash',
            'balance_reference' => 'CASH-1',
            'balance_paid_at' => now(),
        ]);

        $response = $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/payments/history')
            ->assertOk()
            ->assertJsonPath('data.summary.total_paid', 2000)
            ->assertJsonPath('data.summary.balance_due', 0);

        $balance = collect($response->json('data.payments'))->firstWhere('payment_type', 'balance');
        $this->assertSame('Cash', $balance['payment_method_label']);
        $this->assertSame('verified', $balance['payment_status']);
    }

    public function test_passenger_only_sees_their_own_payments(): void
    {
        [, $booking] = $this->rental();
        $other = User::factory()->create([
            'organization_id' => $booking->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/payments/history')
            ->assertOk()
            ->assertJsonPath('data.summary.total_paid', 0)
            ->assertJsonCount(0, 'data.payments');
    }

    public function test_operator_cannot_use_the_passenger_history(): void
    {
        [, $booking] = $this->rental();
        $operator = User::factory()->create([
            'organization_id' => $booking->organization_id,
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/payments/history')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: BoatBooking}
     */
    private function rental(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
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

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-PAX-1',
            'customer_name' => 'Maria Passenger',
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2000.00',
            'down_payment_percent' => '50.00',
            'down_payment_amount' => '1000.00',
            'remaining_balance' => '1000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
        ]);
        $booking->update([
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'gcash_reference' => 'GC-100',
        ]);

        return [$passenger, $booking->fresh()];
    }
}
