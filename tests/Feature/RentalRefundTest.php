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
use App\Models\RentalPayment;
use App\Models\RentalRefundRequest;
use App\Models\User;
use App\Services\RentalCommissionService;
use App\Support\RentalPaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RentalRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $passenger;

    private Boat $boat;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.xendit.enabled' => true,
            'services.xendit.secret_key' => 'xnd_development_test',
            'services.xendit.base_url' => 'https://api.xendit.co',
            'services.xendit.webhook_token' => 'hook-token',
        ]);

        $this->admin = User::factory()->create(['role' => UserRole::Administrator]);
        OrganizationCommissionSetting::query()->create([
            'organization_id' => $this->admin->organization_id,
            'commission_enabled' => true,
            'commission_percentage' => '10.00',
            'effective_date' => now()->toDateString(),
        ]);
        $this->operator = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Operator]);
        $this->passenger = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Passenger]);
        $this->boat = Boat::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $this->boat->operator?->update(['user_id' => $this->operator->id]);
    }

    public function test_unpaid_rental_is_cancelled_with_nothing_to_refund(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::AwaitingPayment, payments: []);

        $this->cancelAsPassenger($booking);

        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
        Http::assertNothingSent();
    }

    public function test_passenger_cancelling_a_downpayment_rental_gets_no_refund(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed);

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.can_passenger_cancel', true);

        $this->cancelAsPassenger($booking);

        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
        Http::assertNothingSent();
    }

    public function test_passenger_cancelling_a_full_payment_opens_admin_refund_review(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed, payments: [['full_rental', 200000]]);

        $this->cancelAsPassenger($booking);

        Http::assertNothingSent();

        $refund = RentalRefundRequest::query()->firstOrFail();
        $this->assertSame(RentalRefundRequest::TYPE_PASSENGER_CANCELLATION, $refund->type);
        $this->assertSame(RentalRefundRequest::STATUS_PENDING_REVIEW, $refund->status);
        $this->assertSame(100000, (int) $refund->refund_amount);
        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertNull($booking->fresh('commission')->commission?->commission_reversed_at);
    }

    public function test_payments_page_lists_pending_passenger_refunds(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed, payments: [['full_rental', 200000]]);
        $this->cancelAsPassenger($booking);

        $this->actingAs($this->admin)
            ->get('/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('payments/Index')
                ->has('refunds', 1)
                ->where('refunds.0.status', RentalRefundRequest::STATUS_PENDING_REVIEW)
                ->where('pendingPaymentActions', 1));
    }

    public function test_admin_approval_refunds_the_amount_above_downpayment(): void
    {
        Http::fake(['api.xendit.co/refunds' => Http::response(['id' => 'rfd-2', 'status' => 'SUCCEEDED'])]);
        $booking = $this->booking(BoatBookingStatus::Confirmed, payments: [['downpayment', 100000], ['full_payment', 100000]]);
        $this->cancelAsPassenger($booking);

        $refund = RentalRefundRequest::query()->firstOrFail();
        $this->assertSame(RentalRefundRequest::STATUS_PENDING_REVIEW, $refund->status);
        Http::assertNothingSent();

        $this->actingAs($this->admin)
            ->patch("/bookings/refund-requests/{$refund->id}", [
                'action' => 'approve',
                'approved_amount' => 1000,
                'admin_notes' => 'Valid passenger cancel before trip day.',
                'confirm' => true,
            ])
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request['payment_request_id'] === 'pr-2');
        $this->assertSame(RentalRefundRequest::STATUS_REFUNDED, $refund->fresh()->status);

        $lines = collect(app(RentalPaymentLedger::class)->lines($booking->fresh(['rentalPayments', 'commission'])));
        $this->assertNotNull($lines->firstWhere('payment_status', 'refunded'));
    }

    public function test_passenger_cannot_cancel_on_trip_day(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed, payments: [['full_rental', 200000]]);
        $booking->update(['booking_date' => now('Asia/Manila')->toDateString()]);

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.can_passenger_cancel', false);

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/cancel", ['cancellation_reason' => 'Change of Plans'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cancellation is only available before the trip date.');

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
        Http::assertNothingSent();
    }

    public function test_operator_cancellation_lets_the_passenger_take_a_full_refund(): void
    {
        Http::fake(['api.xendit.co/refunds' => Http::response(['id' => 'rfd-3', 'status' => 'SUCCEEDED'])]);
        $booking = $this->booking(BoatBookingStatus::Confirmed);

        $this->actingAs($this->operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/cancel")->assertOk();

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.can_choose_refund_or_reschedule', true)
            ->assertJsonPath('data.refund_request.status', RentalRefundRequest::STATUS_AWAITING_CHOICE);

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/refund-choice", ['choice' => 'refund'])
            ->assertOk()
            ->assertJsonPath('data.refund_request.status', RentalRefundRequest::STATUS_REFUNDED);

        Http::assertSent(fn (HttpRequest $request) => (float) $request['amount'] === 1000.0);
        $this->assertNotNull($booking->fresh('commission')->commission?->commission_reversed_at);
    }

    public function test_operator_cancellation_lets_the_passenger_pick_a_new_date(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/cancel")->assertOk();

        $newDate = now()->addDays(10)->toDateString();
        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/refund-choice", ['choice' => 'reschedule', 'booking_date' => $newDate])
            ->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(BoatBookingStatus::ReschedulePending, $fresh->status);
        $this->assertSame($newDate, $fresh->requested_booking_date?->toDateString());
        $this->assertSame(RentalRefundRequest::STATUS_RESCHEDULED, RentalRefundRequest::query()->value('status'));
        Http::assertNothingSent();
    }

    public function test_admin_cannot_cancel_a_confirmed_rental(): void
    {
        $booking = $this->booking(BoatBookingStatus::Confirmed);

        $this->actingAs($this->admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertInertia(fn ($page) => $page->where('booking.can_cancel', false));

        $this->actingAs($this->admin)
            ->patch("/boat-bookings/{$booking->id}/review", ['action' => 'cancel', 'cancellation_reason' => 'Boat needs urgent repair'])
            ->assertRedirect();

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseMissing('rental_refund_requests', ['boat_booking_id' => $booking->id]);
    }

    public function test_weather_hold_is_reschedule_only(): void
    {
        $booking = $this->booking(BoatBookingStatus::WeatherHold);

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.can_choose_refund_or_reschedule', false);

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/refund-choice", ['choice' => 'refund'])
            ->assertUnprocessable();
    }

    public function test_pending_xendit_refund_completes_from_webhook(): void
    {
        Http::fake(['api.xendit.co/refunds' => Http::response(['id' => 'rfd-4', 'status' => 'PENDING'])]);
        $booking = $this->booking(BoatBookingStatus::Confirmed);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/cancel");
        $this->actingAs($this->passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/refund-choice", ['choice' => 'refund']);

        $this->assertSame(RentalRefundRequest::STATUS_REFUNDING, RentalRefundRequest::query()->value('status'));

        $this->withHeader('x-callback-token', 'hook-token')
            ->postJson('/api/xendit/webhook', ['event' => 'refund.succeeded', 'data' => ['id' => 'rfd-4', 'status' => 'SUCCEEDED', 'amount' => 1000]])
            ->assertOk()
            ->assertJsonPath('refund_synced', true);

        $this->assertSame(RentalRefundRequest::STATUS_REFUNDED, RentalRefundRequest::query()->value('status'));
    }

    public function test_failed_xendit_refund_can_be_recorded_manually_by_the_admin(): void
    {
        Http::fake(['api.xendit.co/refunds' => Http::response(['error_code' => 'REFUND_NOT_SUPPORTED', 'message' => 'Not supported'], 400)]);
        $booking = $this->booking(BoatBookingStatus::Confirmed);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/cancel");
        $this->actingAs($this->passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/refund-choice", ['choice' => 'refund']);

        $refund = RentalRefundRequest::query()->firstOrFail();
        $this->assertSame(RentalRefundRequest::STATUS_FAILED, $refund->status);
        $this->assertStringContainsString('REFUND_NOT_SUPPORTED', (string) $refund->failure_message);

        $this->actingAs($this->admin)
            ->get('/payments')
            ->assertInertia(fn ($page) => $page->where('pendingPaymentActions', 1));

        $this->actingAs($this->admin)
            ->patch("/bookings/refund-requests/{$refund->id}", ['action' => 'record_manual', 'manual_reference' => '1012345678901'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RentalRefundRequest::STATUS_REFUNDED, $refund->fresh()->status);
        $this->actingAs($this->admin)
            ->get('/payments')
            ->assertInertia(fn ($page) => $page->where('pendingPaymentActions', 0));
    }

    public function test_admin_of_another_barangay_cannot_touch_the_refund(): void
    {
        Http::fake();
        $booking = $this->booking(BoatBookingStatus::Confirmed, payments: [['full_rental', 200000]]);
        $this->cancelAsPassenger($booking);
        $refund = RentalRefundRequest::query()->firstOrFail();
        $this->assertSame(RentalRefundRequest::STATUS_PENDING_REVIEW, $refund->status);

        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->patch("/bookings/refund-requests/{$refund->id}", [
                'action' => 'approve',
                'approved_amount' => 1000,
                'admin_notes' => 'Should not work across barangays.',
                'confirm' => true,
            ])
            ->assertForbidden();
    }

    private function cancelAsPassenger(BoatBooking $booking): void
    {
        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/cancel", ['cancellation_reason' => 'Change of Plans'])
            ->assertOk();
    }

    /**
     * @param  list<array{0: string, 1: int}>  $payments
     */
    private function booking(BoatBookingStatus $status, array $payments = [['downpayment', 100000]]): BoatBooking
    {
        $snapshot = app(RentalCommissionService::class)->snapshotForBooking($this->admin->organization_id, 2000, 1000);

        $booking = BoatBooking::query()->create([
            'organization_id' => $this->admin->organization_id,
            'boat_id' => $this->boat->id,
            'user_id' => $this->passenger->id,
            'code' => 'RENT-'.fake()->unique()->numerify('####'),
            'customer_name' => 'Maria Passenger',
            'contact_number' => '09180001111',
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
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

        foreach ($payments as $index => [$type, $amount]) {
            RentalPayment::query()->create([
                'organization_id' => $booking->organization_id,
                'boat_booking_id' => $booking->id,
                'provider' => 'xendit',
                'payment_type' => $type,
                'amount' => $amount,
                'currency' => 'PHP',
                'status' => 'paid',
                'checkout_session_id' => 'pr-'.($index + 1),
                'provider_payment_id' => 'py-'.$booking->code.'-'.$index,
                'paid_at' => now(),
            ]);
        }

        if ($payments !== []) {
            $booking->update(['payment_status' => BookingPaymentStatus::Verified, 'payment_verified_at' => now()]);
            app(RentalCommissionService::class)->generateIfEligible($booking->fresh());
        }

        $booking->update(['status' => $status]);

        return $booking->fresh();
    }
}
