<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\RentalCommissionService;
use App\Services\RentalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FullRentalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Boat $boat;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Administrator]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $this->admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '2.00',
            'effective_date' => now()->toDateString(),
        ]);
        $this->passenger = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Passenger]);
        $this->boat = Boat::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
    }

    public function test_full_rental_paid_online_clears_balance_and_pays_operator_the_whole_net(): void
    {
        $booking = $this->awaitingPaymentBooking();
        $payment = $this->paidFullRental($booking);

        $this->assertTrue(app(RentalPaymentService::class)->applyVerifiedXenditFullRental($booking->fresh(), $payment));

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->status);
        $this->assertEquals(0.0, (float) $booking->remaining_balance);
        $this->assertTrue($booking->balanceIsSettled());

        $commission = app(RentalCommissionService::class)->generateIfEligible($booking);
        $this->assertEquals(2000.0, (float) $commission->verified_downpayment);
        $this->assertEquals(40.0, (float) $commission->commission_amount);
        $this->assertEquals(1960.0, (float) $commission->operator_net_amount);

        $this->actingAs($this->admin)
            ->get('/operator-payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions.0.barangay_collected', '₱2,000.00')
                ->where('commissions.0.operator_net', '₱1,960.00')
                ->where('commissions.0.remaining_balance_raw', 0)
                ->where('commissions.0.history.0.title', 'Full payment verified'));
    }

    public function test_older_full_rental_records_are_repaired(): void
    {
        $booking = $this->awaitingPaymentBooking();
        $this->paidFullRental($booking);
        $booking->update([
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'balance_status' => 'paid',
        ]);
        $booking->update(['remaining_balance' => '1400.00']);
        RentalCommission::query()->updateOrCreate(['boat_booking_id' => $booking->id], [
            'organization_id' => $booking->organization_id,
            'operator_id' => $this->boat->operator?->id,
            'rental_amount' => '2000.00',
            'verified_downpayment' => '600.00',
            'commission_percentage' => '2.00',
            'commission_amount' => '40.00',
            'operator_net_amount' => '560.00',
            'payment_verified_at' => now(),
            'settlement_status' => 'collected_with_rental',
            'operator_payout_status' => 'on_hold',
        ]);

        $this->actingAs($this->admin)->get('/operator-payouts')->assertOk();

        $this->assertEquals(0.0, (float) $booking->fresh()->remaining_balance);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(2000.0, (float) $commission->verified_downpayment);
        $this->assertEquals(1960.0, (float) $commission->operator_net_amount);
    }

    private function awaitingPaymentBooking(): BoatBooking
    {
        $snapshot = app(RentalCommissionService::class)->snapshotForBooking($this->admin->organization_id, 2000, 600);

        return BoatBooking::query()->create([
            'organization_id' => $this->admin->organization_id,
            'boat_id' => $this->boat->id,
            'user_id' => $this->passenger->id,
            'code' => 'BR-2026-'.fake()->unique()->numerify('#####'),
            'customer_name' => 'Maria Passenger',
            'contact_number' => '09180001111',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->addDays(5)->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2000.00',
            'down_payment_percent' => '30.00',
            'down_payment_amount' => '600.00',
            'remaining_balance' => '1400.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
            ...$snapshot,
        ]);
    }

    private function paidFullRental(BoatBooking $booking): RentalPayment
    {
        return RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'full_rental',
            'amount' => 200000,
            'currency' => 'PHP',
            'status' => 'paid',
            'checkout_session_id' => 'ps-full-'.$booking->id,
            'provider_payment_id' => 'py-full-'.$booking->id,
            'paid_at' => now(),
        ]);
    }
}
