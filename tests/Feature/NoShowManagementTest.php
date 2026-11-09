<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\GcashPayee;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowRefundStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\PassengerAttendanceStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BookingPassenger;
use App\Models\Organization;
use App\Models\OperatorNoShowReport;
use App\Models\OrganizationRoute;
use App\Models\PassengerNoShowReport;
use App\Models\RentalPayment;
use App\Models\RentalRefundRequest;
use App\Models\User;
use App\Notifications\OperatorNoShowDecisionNotification;
use App\Notifications\PassengerNoShowNotification;
use App\Notifications\TripConfirmationNotification;
use App\Services\RentalRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NoShowManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $passenger;

    private User $operator;

    private Boat $boat;

    private OrganizationRoute $route;

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
        $this->passenger = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Passenger]);
        $this->operator = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Operator]);
        $this->route = OrganizationRoute::query()->create([
            'organization_id' => $this->admin->organization_id,
            'name' => 'Lakandula ↔ Sagisi',
            'origin' => 'Lakandula',
            'destination' => 'Sagisi',
            'trip_type' => OrganizationRouteType::ExclusiveBoatRental->value,
            'rental_price' => 4500,
            'down_payment_percent' => 20,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->boat = $this->makeBoat('Original Boat', $this->operator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Case A: boat / operator no-show -------------------------------------------------

    public function test_passenger_reports_operator_no_show_with_reason_after_grace_period(): void
    {
        $this->freezeManila('2026-10-15 09:16:00');
        $booking = $this->booking();

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'both_not_arrived', 'note' => 'No boat at the pier.'])
            ->assertOk();

        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertSame('both_not_arrived', $report->report_reason);
        $this->assertSame(OperatorNoShowReportStatus::Pending, $report->status);
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
    }

    public function test_operator_no_show_report_is_blocked_before_grace_period_and_reason_is_validated(): void
    {
        $this->freezeManila('2026-10-15 09:10:00');
        $booking = $this->booking();

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'boat_not_arrived'])
            ->assertUnprocessable();

        $this->freezeManila('2026-10-15 09:20:00');

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'weather'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('operator_no_show_reports', 0);
    }

    public function test_operator_no_show_grace_period_is_configurable_per_barangay(): void
    {
        Organization::query()->whereKey($this->admin->organization_id)->update(['operator_no_show_grace_minutes' => 30]);
        $this->freezeManila('2026-10-15 09:20:00');
        $booking = $this->booking();

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'operator_not_arrived'])
            ->assertUnprocessable();

        $this->freezeManila('2026-10-15 09:31:00');

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'operator_not_arrived'])
            ->assertOk();
    }

    public function test_duplicate_operator_no_show_report_is_rejected(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');
        $booking = $this->booking();

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'boat_not_arrived'])
            ->assertOk();

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'boat_not_arrived'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('operator_no_show_reports', 1);
    }

    public function test_admin_must_add_notes_to_verify(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');
        Notification::fake();
        $booking = $this->booking();
        $report = $this->reportOperatorNoShow($booking);

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify'])
            ->assertSessionHasErrors('admin_notes');
        $this->assertSame(OperatorNoShowReportStatus::Pending, $report->fresh()->status);

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify', 'admin_notes' => 'No boat at the pier, operator unreachable.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(OperatorNoShowReportStatus::Verified, $report->fresh()->status);
        $this->assertDatabaseCount('operator_no_show_violations', 1);
        Notification::assertSentTo($this->passenger, OperatorNoShowDecisionNotification::class);
    }

    public function test_operator_no_show_refund_is_sent_through_xendit(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');
        Http::fake(['api.xendit.co/refunds' => Http::response(['id' => 'rfd-ns-1', 'status' => 'SUCCEEDED'])]);
        $booking = $this->booking(paid: true);
        $report = $this->verifiedOperatorNoShow($booking);

        $this->assertSame(OperatorNoShowRefundStatus::Approved, $report->fresh()->refund_status);

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'refund_xendit'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rental_refund_requests', [
            'boat_booking_id' => $booking->id,
            'type' => RentalRefundRequest::TYPE_OPERATOR_NO_SHOW,
            'status' => RentalRefundRequest::STATUS_REFUNDED,
            'refund_amount' => 90000,
        ]);

        $report->refresh();
        $this->assertSame(OperatorNoShowRefundStatus::Refunded, $report->refund_status);
        $this->assertNotNull($report->rental_refund_request_id);
        $this->assertNotNull($report->resolved_at);
        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_failed_xendit_refund_can_be_recorded_manually_and_is_not_duplicated(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');
        Http::fake(['api.xendit.co/refunds' => Http::response(['message' => 'Refund not allowed'], 400)]);
        $booking = $this->booking(paid: true);
        $report = $this->verifiedOperatorNoShow($booking);

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'refund_xendit']);

        $refund = RentalRefundRequest::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertSame(RentalRefundRequest::STATUS_FAILED, $refund->status);
        $this->assertSame(OperatorNoShowRefundStatus::Processing, $report->fresh()->refund_status);

        try {
            app(RentalRefundService::class)->refundForOperatorNoShow($booking->fresh(), $this->admin);
            $this->fail('A second refund must not be started.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('rental_refund_requests', 1);
        }

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), [
                'action' => 'complete_refund',
                'refund_amount' => 900,
                'refunded_at' => '2026-10-15',
                'refund_reference' => '1012345678901',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RentalRefundRequest::STATUS_REFUNDED, $refund->fresh()->status);
        $this->assertSame(OperatorNoShowRefundStatus::Refunded, $report->fresh()->refund_status);
        $this->assertSame('1012345678901', $report->fresh()->refund_reference);
        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
    }

    // ---- Case B: passenger no-show -------------------------------------------------------

    public function test_operator_reports_passenger_no_show_and_rental_is_cancelled_without_refund(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        Notification::fake();
        $booking = $this->booking(paid: true);
        $this->operatorReady($booking);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", [
                'declaration_confirmed' => true,
                'note' => 'Waited at Lakandula port.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'passenger_no_show');

        $report = PassengerNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertSame(PassengerNoShowReport::STATUS_CONFIRMED, $report->status);
        $this->assertSame(PassengerNoShowReport::CANCELLATION_REASON, $report->cancellation_reason);
        $this->assertFalse($report->refund_eligible);
        $this->assertTrue($report->declaration_confirmed);
        $this->assertEquals(900.0, (float) $report->amount_paid);

        $this->assertSame(BoatBookingStatus::PassengerNoShow, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
        $this->assertDatabaseHas('rental_payments', ['boat_booking_id' => $booking->id, 'status' => 'paid']);
        Http::assertNothingSent();

        Notification::assertSentTo(
            $this->passenger,
            TripConfirmationNotification::class,
            fn (TripConfirmationNotification $notification) => $notification->toArray($this->passenger)['title'] === 'Rental Cancelled — Passenger No-Show',
        );
    }

    public function test_passenger_no_show_requires_declaration_grace_period_and_no_check_in(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        $booking = $this->booking();
        $this->operatorReady($booking);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('declaration_confirmed');

        $booking->passengers()->first()->update(['attendance_status' => PassengerAttendanceStatus::Arrived]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('passenger_no_show_reports', 0);
    }

    public function test_passenger_no_show_blocked_before_grace_period_and_for_other_operators(): void
    {
        $this->freezeManila('2026-10-15 09:10:00');
        $booking = $this->booking();
        $this->operatorReady($booking);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->freezeManila('2026-10-15 09:20:00');
        $stranger = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::Operator]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertForbidden();

        $this->assertDatabaseCount('passenger_no_show_reports', 0);
    }

    public function test_duplicate_passenger_no_show_is_rejected(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        $booking = $this->booking();
        $this->operatorReady($booking);
        $this->reportPassengerNoShow($booking);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->assertDatabaseCount('passenger_no_show_reports', 1);
    }

    public function test_passenger_disputes_and_admin_upholds_the_no_show(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        Notification::fake();
        $booking = $this->booking(paid: true);
        $this->operatorReady($booking);
        $this->reportPassengerNoShow($booking);

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.passenger_no_show_report.can_dispute', true)
            ->assertJsonPath('data.passenger_no_show_report.dispute_window_days', 1);

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show/dispute", ['reason' => 'I was at the port but the boat was elsewhere.'])
            ->assertOk();

        $report = PassengerNoShowReport::query()->firstOrFail();
        $this->assertSame(PassengerNoShowReport::STATUS_DISPUTED, $report->status);
        Notification::assertSentTo($this->admin, PassengerNoShowNotification::class);

        $this->actingAs($this->admin)
            ->patch(route('bookings.passenger-no-show-reports.resolve', $report), [
                'decision' => 'uphold',
                'admin_notes' => 'Operator photo shows the boat at the port.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PassengerNoShowReport::STATUS_UPHELD, $report->fresh()->status);
        $this->assertSame(BoatBookingStatus::PassengerNoShow, $booking->fresh()->status);
        $this->assertDatabaseCount('rental_refund_requests', 0);
    }

    public function test_passenger_cannot_dispute_after_one_day_and_auto_expire_keeps_passenger_no_show(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        Notification::fake();
        $booking = $this->booking(paid: true);
        $this->operatorReady($booking);
        $this->reportPassengerNoShow($booking);

        $this->freezeManila('2026-10-16 09:20:00');

        $this->actingAs($this->passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertJsonPath('data.passenger_no_show_report.can_dispute', false);

        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show/dispute", [
                'reason' => 'I was at the port but the boat was elsewhere.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking');

        $this->artisan('passenger-no-show:expire-disputes')->assertSuccessful();

        $report = PassengerNoShowReport::query()->firstOrFail();
        $this->assertSame(PassengerNoShowReport::STATUS_UPHELD, $report->status);
        $this->assertStringContainsString('1 day', (string) $report->resolution_notes);
        $this->assertSame(BoatBookingStatus::PassengerNoShow, $booking->fresh()->status);
        $this->assertSame('Passenger No-Show', $booking->fresh()->cancellation_reason);
        $this->assertDatabaseCount('rental_refund_requests', 0);
        Notification::assertSentTo($this->passenger, PassengerNoShowNotification::class);
    }

    public function test_admin_reverses_incorrect_no_show_and_passenger_can_choose_refund(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        Notification::fake();
        $booking = $this->booking(paid: true);
        $this->operatorReady($booking);
        $this->reportPassengerNoShow($booking);
        $report = PassengerNoShowReport::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('bookings.passenger-no-show-reports.resolve', $report), [
                'decision' => 'reverse',
                'admin_notes' => 'Passenger was at the wrong pier because of a wrong pin.',
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(PassengerNoShowReport::STATUS_REVERSED, $report->status);
        $this->assertTrue($report->refund_eligible);
        $this->assertSame(BoatBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertDatabaseHas('rental_refund_requests', [
            'boat_booking_id' => $booking->id,
            'type' => RentalRefundRequest::TYPE_ADMIN_CANCELLED,
        ]);
        Notification::assertSentTo($this->passenger, PassengerNoShowNotification::class);
    }

    public function test_admin_resolve_requires_notes_and_stays_in_organization(): void
    {
        $this->freezeManila('2026-10-15 09:20:00');
        $booking = $this->booking();
        $this->operatorReady($booking);
        $this->reportPassengerNoShow($booking);
        $report = PassengerNoShowReport::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('bookings.passenger-no-show-reports.resolve', $report), ['decision' => 'reverse'])
            ->assertSessionHasErrors('admin_notes');

        $outsider = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($outsider)
            ->patch(route('bookings.passenger-no-show-reports.resolve', $report), [
                'decision' => 'reverse',
                'admin_notes' => 'Trying from another barangay.',
            ])
            ->assertNotFound();

        $this->assertSame(PassengerNoShowReport::STATUS_CONFIRMED, $report->fresh()->status);
    }

    // ---- helpers -------------------------------------------------------------------------

    private function freezeManila(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, 'Asia/Manila'));
    }

    private function makeBoat(string $name, User $operatorUser, int $capacity = 20): Boat
    {
        $boat = Boat::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'name' => $name,
            'capacity' => $capacity,
            'status' => BoatStatus::OnDuty,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operatorUser->id]);
        $boat->rentalDestinations()->attach($this->route->id);

        return $boat;
    }

    private function booking(bool $paid = false): BoatBooking
    {
        $booking = BoatBooking::query()->create([
            'organization_id' => $this->admin->organization_id,
            'boat_id' => $this->boat->id,
            'user_id' => $this->passenger->id,
            'code' => 'BR-2026-'.fake()->unique()->numerify('#####'),
            'customer_name' => 'Test Passenger',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-15',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Confirmed,
            'rental_price' => '4500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '900.00',
            'remaining_balance' => '3600.00',
            'gcash_payee' => GcashPayee::Barangay,
            'payment_status' => $paid ? BookingPaymentStatus::Verified : BookingPaymentStatus::Unpaid,
            'payment_verified_at' => $paid ? now() : null,
        ]);

        foreach (['Passenger One', 'Passenger Two'] as $index => $name) {
            BookingPassenger::query()->create([
                'organization_id' => $this->admin->organization_id,
                'boat_booking_id' => $booking->id,
                'full_name' => $name,
                'age' => 25 + $index,
                'gender' => 'female',
                'sort_order' => $index + 1,
            ]);
        }

        if ($paid) {
            RentalPayment::query()->create([
                'organization_id' => $booking->organization_id,
                'boat_booking_id' => $booking->id,
                'provider' => 'xendit',
                'payment_type' => 'downpayment',
                'amount' => 90000,
                'currency' => 'PHP',
                'status' => 'paid',
                'checkout_session_id' => 'pr-'.$booking->id,
                'provider_payment_id' => 'py-'.$booking->id,
                'paid_at' => now(),
            ]);
        }

        return $booking->fresh();
    }

    private function reportOperatorNoShow(BoatBooking $booking): OperatorNoShowReport
    {
        $this->actingAs($this->passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['reason' => 'boat_not_arrived'])
            ->assertOk();

        return OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->latest('id')->firstOrFail();
    }

    private function verifiedOperatorNoShow(BoatBooking $booking): OperatorNoShowReport
    {
        $report = $this->reportOperatorNoShow($booking);

        $this->actingAs($this->admin)
            ->patch(route('bookings.no-show-reports.update', $report), [
                'action' => 'verify',
                'admin_notes' => 'Called the operator, no answer. Boat not at the port.',
            ])
            ->assertSessionHasNoErrors();

        return $report->fresh();
    }

    private function operatorReady(BoatBooking $booking): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/confirm-availability")
            ->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/meeting-point-arrival")
            ->assertOk();
    }

    private function reportPassengerNoShow(BoatBooking $booking): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertOk();
    }
}
