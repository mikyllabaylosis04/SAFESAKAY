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
use App\Models\User;
use App\Services\RentalCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFinanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_payments_and_my_commission_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/Index')
                ->has('payments')
                ->has('stats'));

        $this->actingAs($admin)
            ->get(route('my-commission.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('my-commission/Index')
                ->has('commissions')
                ->has('stats'));
    }

    public function test_payments_commission_and_payouts_show_the_same_commission(): void
    {
        [$admin, $booking] = $this->verifiedRental();
        $commission = (float) $booking->fresh('commission')->commission->commission_amount;

        $this->assertSame(50.0, $commission);

        $this->actingAs($admin)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_commission', 50)
                ->where('stats.downpayments', 500)
                ->where('payments.0.booking_reference', $booking->code)
                ->where('payments.0.commission_amount', 50)
                ->where('payments.0.operator_share', 450)
                ->where('payments.0.received_by', 'Barangay'));

        $this->actingAs($admin)
            ->get(route('my-commission.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions.0.rental_reference', $booking->code)
                ->where('commissions.0.status', 'paid')
                ->where('commissions.0.can_mark_paid', false)
                ->where('stats.total_commission', "\u{20B1}50.00"));

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_commission', "\u{20B1}50.00")
                ->missing('payout_accounts'));
    }

    public function test_balance_paid_on_trip_is_shown_on_the_downpayment_row_not_as_a_separate_payment(): void
    {
        [$admin, $booking] = $this->verifiedRental();

        $this->actingAs($admin)
            ->get(route('payments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments', 1)
                ->where('payments.0.balance_status', 'Due on trip')
                ->where('payments.0.balance_amount', 2000));

        app(\App\Services\RentalCompletionService::class)->complete($booking, $admin);

        $this->actingAs($admin)
            ->get(route('payments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments', 1)
                ->where('payments.0.payment_type', 'downpayment')
                ->where('payments.0.balance_status', 'Paid on trip')
                ->where('stats.total_payments', 500));
    }

    public function test_commissions_route_redirects_to_operator_payouts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get('/commissions')
            ->assertRedirect('/operator-payouts');
    }

    /**
     * @return array{0: User, 1: BoatBooking}
     */
    private function verifiedRental(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '2.00',
            'effective_date' => now()->toDateString(),
        ]);
        $passenger = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Passenger]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-LEDGER-1',
            'customer_name' => $passenger->name,
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 3,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '500.00',
            'remaining_balance' => '2000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
            ...app(RentalCommissionService::class)->snapshotForBooking($admin->organization_id, 2500, 500),
        ]);
        $booking->update([
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
            'gcash_reference' => 'GC-500',
        ]);
        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());

        return [$admin, $booking->fresh()];
    }
}
