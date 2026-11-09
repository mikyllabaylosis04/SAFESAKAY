<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementMethod;
use App\Enums\GcashPayee;
use App\Enums\OperatorGcashStatus;
use App\Enums\OperatorPayoutStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OperatorGcashAccount;
use App\Models\OrganizationCommissionSetting;
use App\Models\RentalCommission;
use App\Models\User;
use App\Services\RentalCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPayoutWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_payouts_page_no_longer_lists_operator_gcash_accounts(): void
    {
        [$admin] = $this->completedRental();

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commissions/Index')
                ->missing('payout_accounts')
                ->where('can_manage', true));
    }

    public function test_gcash_payout_requires_reference_and_is_saved_as_gcash(): void
    {
        [$admin, $booking] = $this->completedRental();
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertSame(OperatorPayoutStatus::ReadyForPayout, $commission->fresh()->operator_payout_status);

        $this->actingAs($admin)
            ->post(route('commissions.record-payout', $commission), [
                'payment_method' => 'gcash',
                'confirm_payout' => true,
            ])
            ->assertSessionHasErrors('gcash_reference');

        $this->actingAs($admin)
            ->post(route('commissions.record-payout', $commission), [
                'payment_method' => 'gcash',
                'gcash_reference' => '1012345678901',
                'confirm_payout' => true,
            ])
            ->assertRedirect();

        $fresh = $commission->fresh();
        $this->assertSame(OperatorPayoutStatus::PaidOut, $fresh->operator_payout_status);
        $this->assertSame(CommissionSettlementMethod::Gcash, $fresh->operator_payout_method);
        $this->assertSame('1012345678901', $fresh->operator_payout_reference);
    }

    public function test_cash_payout_is_saved_as_cash(): void
    {
        [$admin, $booking] = $this->completedRental();
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('commissions.record-payout', $commission), [
                'payment_method' => 'cash',
                'confirm_payout' => true,
            ])
            ->assertRedirect();

        $this->assertSame(CommissionSettlementMethod::Cash, $commission->fresh()->operator_payout_method);
    }

    public function test_admin_from_another_barangay_cannot_update_no_show_report(): void
    {
        [, $booking] = $this->completedRental();
        $report = $booking->operatorNoShowReports()->create([
            'organization_id' => $booking->organization_id,
            'reported_by_user_id' => $booking->user_id,
            'status' => 'pending',
            'reported_at' => now(),
        ]);
        $outsider = User::factory()->create(['role' => UserRole::Administrator]);

        $response = $this->actingAs($outsider)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('pending', $report->fresh()->status->value);
    }

    public function test_rental_details_include_no_show_report_and_manage_flag(): void
    {
        [$admin, $booking] = $this->completedRental();

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Show')
                ->where('no_show_report', null)
                ->where('can_manage', true));
    }

    public function test_payouts_explain_why_a_payout_is_on_hold(): void
    {
        [$admin, $booking] = $this->confirmedRental(now()->subDay()->toDateString());

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions.0.payout_status', 'on_hold')
                ->where('commissions.0.payout_hold_reason', 'Trip not marked completed'));

        $booking->update(['status' => BoatBookingStatus::WeatherHold]);

        $this->actingAs($admin)
            ->get(route('operator-payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commissions.0.payout_hold_reason', 'Weather hold · needs a new date'));
    }

    public function test_admin_completes_trip_and_unpaid_balance_is_recorded_as_paid_during_trip(): void
    {
        [$admin, $booking] = $this->confirmedRental(now()->subDay()->toDateString());

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.can_complete', true)
                ->where('booking.complete_settles_balance', true));

        $this->actingAs($admin)
            ->post(route('boat-bookings.complete', $booking))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(BoatBookingStatus::Completed, $fresh->status);
        $this->assertSame('cash', $fresh->balance_method);
        $this->assertSame($admin->id, $fresh->balance_recorded_by);
        $this->assertSame(
            OperatorPayoutStatus::ReadyForPayout,
            RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail()->operator_payout_status,
        );
    }

    public function test_admin_cannot_complete_a_trip_before_its_date(): void
    {
        [$admin, $booking] = $this->confirmedRental(now()->addDays(3)->toDateString());

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.can_complete', false)
                ->where('booking.complete_blocked_reason', fn ($reason) => str_starts_with((string) $reason, 'Available on the trip date')));

        $this->actingAs($admin)
            ->post(route('boat-bookings.complete', $booking))
            ->assertSessionHasErrors('booking');

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_staff_cannot_complete_trips(): void
    {
        [$admin, $booking] = $this->confirmedRental(now()->subDay()->toDateString());
        $staff = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Staff]);

        $response = $this->actingAs($staff)->post(route('boat-bookings.complete', $booking));

        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
    }

    /**
     * @return array{0: User, 1: BoatBooking}
     */
    private function confirmedRental(string $date): array
    {
        [$admin, $completed] = $this->completedRental();

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $completed->boat_id,
            'user_id' => $completed->user_id,
            'code' => 'RENT-CONFIRMED-1',
            'customer_name' => $completed->customer_name,
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => $date,
            'start_time' => '13:00',
            'passenger_count' => 2,
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
        ]);
        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());
        $completed->commission?->delete();
        $completed->delete();

        return [$admin, $booking->fresh()];
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: Boat}
     */
    private function completedRental(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '2.00',
            'effective_date' => now()->toDateString(),
        ]);
        $operator = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Operator]);
        $passenger = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Passenger]);
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
            'code' => 'RENT-PAYOUT-1',
            'customer_name' => $passenger->name,
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '500.00',
            'remaining_balance' => '0.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
            ...app(RentalCommissionService::class)->snapshotForBooking($admin->organization_id, 2500, 500),
        ]);
        $booking->update([
            'status' => BoatBookingStatus::Completed,
            'completed_at' => now(),
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
        ]);
        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());

        return [$admin, $booking->fresh(), $boat->fresh('operator')];
    }
}
