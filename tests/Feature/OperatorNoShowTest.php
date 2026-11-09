<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementStatus;
use App\Enums\GcashPayee;
use App\Enums\OperatorGcashStatus;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OperatorNoShowRescheduleStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BookingPassenger;
use App\Models\OperatorGcashAccount;
use App\Models\OperatorNoShowReport;
use App\Models\RentalCommission;
use App\Models\User;
use App\Services\OperatorNoShowService;
use App\Services\RentalCommissionService;
use App\Notifications\OperatorNoShowReportedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorNoShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_report_30_minutes_after_manila_departure(): void
    {
        $this->freezeManila('2026-10-28 12:47:00');

        [$admin, $booking, $passenger] = $this->confirmedRental();
        $booking->update([
            'booking_date' => '2026-10-28',
            'start_time' => '12:00',
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.can_report_operator_no_show', true);
    }

    public function test_passenger_cannot_report_operator_no_show_before_grace_period(): void
    {
        $this->freezeManila('2026-10-15 09:14:00');

        [$admin, $booking, $passenger] = $this->confirmedRental();

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show")
            ->assertUnprocessable();

        $this->assertDatabaseMissing('operator_no_show_reports', [
            'boat_booking_id' => $booking->id,
        ]);
    }

    public function test_passenger_can_report_operator_no_show_after_scheduled_start(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger] = $this->confirmedRental();

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", [
                'note' => 'Operator wala sa pickup point.',
            ])
            ->assertOk()
            ->assertJsonPath('data.can_report_operator_no_show', false)
            ->assertJsonPath('data.operator_no_show_report_pending', true);

        $this->assertDatabaseHas('operator_no_show_reports', [
            'boat_booking_id' => $booking->id,
            'reported_by_user_id' => $passenger->id,
            'status' => OperatorNoShowReportStatus::Pending->value,
        ]);

        Notification::assertSentTo($admin, OperatorNoShowReportedNotification::class);
    }

    public function test_passenger_report_notifies_the_assigned_operator(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show")
            ->assertOk();

        Notification::assertSentTo($operator, OperatorNoShowReportedNotification::class);
        Notification::assertSentTo($admin, OperatorNoShowReportedNotification::class);
        Notification::assertNotSentTo($passenger, OperatorNoShowReportedNotification::class);
    }

    public function test_admin_can_view_passenger_uploaded_no_show_proof_photo(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Storage::fake('public');

        [$admin, $booking, $passenger] = $this->confirmedRental();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/report-no-show", [
                'note' => 'Boat never arrived at the pier.',
                'reason' => 'both_not_arrived',
                'evidence' => UploadedFile::fake()->image('proof.jpg', 640, 480),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $report = OperatorNoShowReport::query()->firstOrFail();
        $this->assertNotNull($report->evidence_photo_path);
        Storage::disk('public')->assertExists($report->evidence_photo_path);

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('no_show_report.evidence_photo_url', '/storage/'.$report->evidence_photo_path));

        $this->actingAs($admin)
            ->get(route('bookings.no-show-reports.evidence', $report))
            ->assertOk();

        $this->get('/storage/'.$report->evidence_photo_path)
            ->assertOk();
    }

    public function test_operator_cannot_mark_passenger_no_show_before_trip_day(): void
    {
        $this->freezeManila('2026-10-14 16:00:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
    }

    private function prepareOperatorAttendanceForNoShow(BoatBooking $booking, User $operator): void
    {
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/confirm-availability")
            ->assertOk();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/meeting-point-arrival")
            ->assertOk();
    }

    public function test_operator_cannot_mark_passenger_no_show_until_grace_period_after_trip_time(): void
    {
        $this->freezeManila('2026-10-15 09:10:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_operator_can_mark_passenger_no_show_on_trip_day(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);
        $this->prepareOperatorAttendanceForNoShow($booking, $operator);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'passenger_no_show')
            ->assertJsonPath('data.can_complete', false);

        $this->assertSame(BoatBookingStatus::PassengerNoShow, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->completed_at);
        $this->assertEquals(3600, (float) $booking->fresh()->remaining_balance);
    }

    public function test_operator_cannot_mark_passenger_no_show_the_next_day(): void
    {
        $this->freezeManila('2026-10-16 08:00:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertUnprocessable();

        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_passenger_no_show_waives_unpaid_operator_commission(): void
    {
        $this->freezeManila('2026-10-15 11:00:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);
        $booking->loadMissing('boat.operator');
        OperatorGcashAccount::query()->create([
            'operator_id' => $booking->boat?->operator?->id,
            'organization_id' => $admin->organization_id,
            'account_name' => 'Operator GCash',
            'mobile_number' => '09171234567',
            'qr_path' => 'operator-gcash/demo.png',
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        $booking->update([
            'gcash_payee' => GcashPayee::Operator,
            'commission_enabled' => true,
            'commission_percentage' => '10.00',
            'commission_amount' => '450.00',
            'operator_net_amount' => '4050.00',
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
        ]);
        app(RentalCommissionService::class)->generateIfEligible($booking->fresh());

        $this->assertDatabaseHas('rental_commissions', [
            'boat_booking_id' => $booking->id,
            'settlement_status' => CommissionSettlementStatus::PendingSettlement->value,
        ]);

        $this->prepareOperatorAttendanceForNoShow($booking, $operator);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['declaration_confirmed' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'passenger_no_show');

        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();
        $this->assertSame(CommissionSettlementStatus::Waived, $commission->settlement_status);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'waived')
            ->assertJsonPath('data.0.can_submit', false);
    }

    public function test_verify_marks_operator_no_show_and_approves_full_refund_without_passenger_choice(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger] = $this->confirmedRental();
        $booking->update(['payment_status' => BookingPaymentStatus::Verified, 'payment_verified_at' => now()]);

        $this->actingAs($passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/report-no-show")->assertOk();
        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify', 'admin_notes' => 'Called the operator, no answer.'])
            ->assertRedirect();

        $report->refresh();
        $this->assertSame(OperatorNoShowReportStatus::Verified, $report->status);
        $this->assertSame('refund_approved', $report->resolution?->value);
        $this->assertSame('approved', $report->refund_status?->value);
        $this->assertSame(900.0, (float) $report->refund_amount);
        $this->assertSame(OperatorNoShowService::CANCELLATION_REASON, $booking->fresh()->cancellation_reason);

        Notification::assertSentTo($passenger, \App\Notifications\OperatorNoShowDecisionNotification::class,
            fn ($n) => $n->event === 'operator_no_show_verified_full_refund');

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.operator_no_show_incident.can_choose_resolution', false)
            ->assertJsonPath('data.operator_no_show_incident.can_accept_replacement', false)
            ->assertJsonPath('data.operator_no_show_incident.passenger_status', 'refund_processing');

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/no-show/choose-resolution", ['choice' => OperatorNoShowPassengerChoice::Reschedule->value])
            ->assertUnprocessable();
    }

    public function test_reassignment_and_replacement_actions_are_removed(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');

        [$admin, $booking, $passenger] = $this->confirmedRental();
        $this->actingAs($passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/report-no-show")->assertOk();
        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        foreach (['reassign', 'offer_replacement', 'no_replacement', 'reschedule', 'delayed'] as $action) {
            $this->actingAs($admin)
                ->patch(route('bookings.no-show-reports.update', $report), ['action' => $action, 'admin_notes' => 'Testing removed action'])
                ->assertStatus(422);
        }

        $this->assertSame(OperatorNoShowReportStatus::Pending, $report->fresh()->status);
    }

    public function test_admin_refund_records_gcash_reference_and_notifies_passenger(): void
    {
        $this->test_admin_records_refund_with_amount_date_and_reference();
    }

    public function test_operator_can_confirm_availability_before_trip(): void
    {
        $this->freezeManila('2026-10-14 08:00:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/confirm-availability")
            ->assertOk()
            ->assertJsonPath('data.operator_availability_confirmed', true);

        $booking->refresh();
        $this->assertNotNull($booking->operator_availability_confirmed_at);
        $this->assertSame('confirmed', $booking->trip_confirmation_status?->value);
    }

    public function test_verify_records_operator_violation_strike(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show")
            ->assertOk();

        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify', 'admin_notes' => 'Confirmed with passenger at the pickup point.'])
            ->assertRedirect();

        $this->assertDatabaseHas('operator_no_show_violations', [
            'operator_no_show_report_id' => $report->id,
            'strike_number' => 1,
        ]);

        Notification::assertSentTo(
            $operator,
            \App\Notifications\OperatorNoShowDecisionNotification::class,
            fn ($n) => $n->event === 'operator_no_show_violation_recorded',
        );
    }

    public function test_admin_no_show_reports_page_lists_pending_incidents(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');

        [$admin, $booking, $passenger] = $this->confirmedRental();

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show")
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('bookings.no-show-reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/OperatorNoShows')
                ->where('counts.pending', 1)
                ->where('cases.0.stage', 'pending')
                ->where('cases.0.stage_label', 'Pending Verification')
                ->where('cases.0.can_verify', true)
                ->where('cases.0.booking.code', $booking->code));

        $outsider = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($outsider)
            ->get(route('bookings.no-show-reports'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.pending', 0));
    }

    public function test_admin_no_show_reports_page_lists_passenger_no_show_records(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);
        $this->prepareOperatorAttendanceForNoShow($booking, $operator);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['note' => 'Passenger absent at pickup', 'declaration_confirmed' => true])
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('bookings.no-show-reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('bookings/OperatorNoShows')->where('counts.pending', 0));
    }

    public function test_admin_can_acknowledge_passenger_no_show_to_history(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);
        $this->prepareOperatorAttendanceForNoShow($booking, $operator);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['note' => 'No passenger at pickup', 'declaration_confirmed' => true])
            ->assertOk();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.passenger.acknowledge', $booking))
            ->assertRedirect();

        $booking->refresh();
        $this->assertNotNull($booking->passenger_no_show_admin_acknowledged_at);
        $this->assertSame($admin->id, $booking->passenger_no_show_admin_acknowledged_by);
    }

    public function test_booking_api_exposes_incident_information_for_passenger(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');

        [$admin, $booking, $passenger] = $this->confirmedRental();

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/report-no-show", ['note' => 'Wala ang operator'])
            ->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.incident_information.incident_type', 'operator_no_show')
            ->assertJsonPath('data.incident_information.report_status', 'pending_review')
            ->assertJsonPath('data.incident_information.report_status_label', 'Pending Review')
                ->assertJsonPath('data.incident_summary.has_open_incident', true);
    }

    public function test_passenger_no_show_cancellation_display_not_weather(): void
    {
        $this->freezeManila('2026-10-15 09:30:00');

        [$admin, $booking, $passenger, $operator] = $this->confirmedRental(true);
        $this->prepareOperatorAttendanceForNoShow($booking, $operator);

        $booking->update([
            'weather_hold_at' => now(),
            'weather_reason' => 'rough_seas',
            'weather_review_required' => true,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show", ['note' => 'Absent', 'declaration_confirmed' => true])
            ->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'passenger_no_show')
            ->assertJsonPath('data.weather_hold', false)
            ->assertJsonPath('data.cancellation_display.reason_label', 'Passenger No-Show')
            ->assertJsonPath('data.cancellation_display.payment_status_label', 'Non-refundable')
            ->assertJsonPath('data.cancellation_display.use_weather_message', false)
            ->assertJsonPath('data.overall_payment_status', 'non_refundable')
            ->assertJsonPath('data.overall_payment_status_label', 'Non-refundable')
            ->assertJsonPath('data.weather_reason', null);
    }

    public function test_admin_records_refund_with_amount_date_and_reference(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger] = $this->confirmedRental();
        $booking->update(['payment_status' => BookingPaymentStatus::Verified, 'payment_verified_at' => now()]);

        $this->actingAs($passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/report-no-show")->assertOk();
        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'verify', 'admin_notes' => 'Confirmed with the passenger.'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), [
                'action' => 'complete_refund',
                'refund_amount' => 5000,
                'refunded_at' => '2026-10-15',
                'refund_reference' => 'GCASHREF1234',
            ])
            ->assertSessionHasErrors('refund_amount');

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), [
                'action' => 'complete_refund',
                'refund_amount' => 900,
                'refunded_at' => '2026-10-15',
                'refund_reference' => 'GCASHREF1234',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $report->refresh();
        $this->assertSame('refunded', $report->refund_status?->value);
        $this->assertSame('GCASHREF1234', $report->refund_reference);
        $this->assertSame(900.0, (float) $report->refund_amount);
        $this->assertSame('2026-10-15', $report->refund_processed_at?->timezone('Asia/Manila')->toDateString());
        $this->assertNotNull($report->resolved_at);
        $this->assertDatabaseHas('boat_booking_activities', [
            'boat_booking_id' => $booking->id,
            'event' => 'operator_no_show_refund_completed',
        ]);
        Notification::assertSentTo($passenger, \App\Notifications\OperatorNoShowDecisionNotification::class,
            fn ($n) => $n->event === 'operator_no_show_refund_completed');

        $this->actingAs($admin)
            ->get(route('bookings.no-show-reports'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/OperatorNoShows')
                ->where('counts.refunded', 1)
                ->where('cases.0.stage', 'refunded'));
    }

    public function test_rejected_report_notifies_passenger_and_cannot_be_refunded(): void
    {
        $this->freezeManila('2026-10-15 10:00:00');
        Notification::fake();

        [$admin, $booking, $passenger] = $this->confirmedRental();
        $this->actingAs($passenger, 'sanctum')->postJson("/api/bookings/{$booking->id}/report-no-show")->assertOk();
        $report = OperatorNoShowReport::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'dismiss'])
            ->assertSessionHasErrors('admin_notes');

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), ['action' => 'dismiss', 'admin_notes' => 'Operator was at the pier on time with photos.'])
            ->assertRedirect();

        $this->assertSame(OperatorNoShowReportStatus::Dismissed, $report->fresh()->status);
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('operator_no_show_violations', 0);
        Notification::assertSentTo($passenger, \App\Notifications\OperatorNoShowDecisionNotification::class,
            fn ($n) => $n->event === 'operator_no_show_report_rejected');

        $this->actingAs($admin)
            ->patch(route('bookings.no-show-reports.update', $report), [
                'action' => 'complete_refund',
                'refund_amount' => 900,
                'refunded_at' => '2026-10-15',
                'refund_reference' => 'GCASHREF1234',
            ])
            ->assertSessionHasErrors();
    }

    private function freezeManila(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, 'Asia/Manila'));
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User, 3?: User}
     */
    private function confirmedRental(bool $withOperator = false): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);

        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'capacity' => 20,
            'status' => BoatStatus::OnDuty,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-00021',
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
        ]);

        BookingPassenger::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_booking_id' => $booking->id,
            'full_name' => 'Passenger One',
            'age' => 25,
            'gender' => 'female',
            'sort_order' => 1,
        ]);
        BookingPassenger::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_booking_id' => $booking->id,
            'full_name' => 'Passenger Two',
            'age' => 30,
            'gender' => 'male',
            'sort_order' => 2,
        ]);

        if ($withOperator) {
            return [$admin, $booking, $passenger, $operator];
        }

        return [$admin, $booking, $passenger];
    }
}
