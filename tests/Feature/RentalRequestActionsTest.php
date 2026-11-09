<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RentalRequestActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_rental_badge_counts_pending_requests_and_payment_reviews(): void
    {
        [$admin, $booking] = $this->pendingRental();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingBookingRequests', 1));

        $booking->update([
            'status' => BoatBookingStatus::AwaitingPayment,
            'gcash_reference' => 'GCASH-123456',
            'payment_submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingBookingRequests', 1));

        $this->actingAs($admin)
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingBookingRequests', 0));
    }

    public function test_rental_requests_show_newest_first(): void
    {
        [$admin, $older] = $this->pendingRental();
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $older->boat_id,
            'user_id' => $older->user_id,
            'code' => 'BR-2026-00099',
            'customer_name' => 'New Request',
            'package' => 'Bangkay',
            'destination' => 'Bangkay',
            'booking_date' => now()->addWeek()->toDateString(),
            'start_time' => '08:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '1500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '300.00',
            'remaining_balance' => '1200.00',
        ]);

        $this->actingAs($admin)
            ->get(route('bookings.requests'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Requests')
                ->where('bookings.0.id', $newer->id)
                ->where('bookings.0.customer_name', 'New Request')
                ->where('bookings.1.id', $older->id)
                ->has('bookings.0.created_at_label'));
    }

    public function test_rental_requests_include_payment_and_activity_payload(): void
    {
        [$admin, $booking] = $this->pendingRental();

        $this->actingAs($admin)
            ->get(route('bookings.requests'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Requests')
                ->where('bookings.0.id', $booking->id)
                ->where('bookings.0.can_cancel', true)
                ->where('bookings.0.can_approve', true)
                ->where('bookings.0.payment.submitted', false)
                ->where('bookings.0.payment.rental_price_label', '₱4,500.00')
                ->where('bookings.0.payment.down_payment_percent_label', '20%')
                ->where('bookings.0.payment.payment_status_label', 'Unpaid')
                ->where('bookings.0.activities.0.event', 'submitted')
                ->where('bookings.0.activities.0.title', 'Rental Request Submitted'));
    }

    public function test_view_payment_includes_gcash_and_receipt_when_submitted(): void
    {
        [$admin, $booking] = $this->pendingRental();

        $booking->update([
            'gcash_reference' => 'GCASH-123456',
            'payment_receipt_path' => 'receipts/test.png',
            'payment_submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Show')
                ->where('booking.payment.submitted', true)
                ->where('booking.payment.gcash_reference', 'GCASH-123456')
                ->where('booking.payment.payment_status_label', 'For Verification')
                ->where('booking.activities.1.event', 'payment_submitted'));
    }

    public function test_admin_can_cancel_pending_rental_with_reason(): void
    {
        Notification::fake();

        [$admin, $booking, $passenger, $operator] = $this->pendingRental(withPeople: true);

        $this->actingAs($admin)
            ->patch(route('boat-bookings.review', $booking), [
                'action' => 'reject',
                'cancellation_reason' => 'Weather advisory for this date.',
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(BoatBookingStatus::Rejected, $booking->status);
        $this->assertSame('Weather advisory for this date.', $booking->cancellation_reason);
        $this->assertSame($admin->id, $booking->cancelled_by);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertTrue($booking->activities()->where('event', 'cancelled')->exists());

        Notification::assertSentTo($passenger, BookingStatusNotification::class);
        Notification::assertSentTo($operator, BookingStatusNotification::class);
    }

    public function test_completed_rentals_cannot_be_cancelled(): void
    {
        [$admin, $booking] = $this->pendingRental();
        $booking->update(['status' => BoatBookingStatus::Completed]);

        $this->actingAs($admin)
            ->from(route('bookings.requests'))
            ->patch(route('boat-bookings.review', $booking), [
                'action' => 'cancel',
                'cancellation_reason' => 'Should not work.',
            ])
            ->assertRedirect(route('bookings.requests'));

        $this->assertSame(BoatBookingStatus::Completed, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->cancellation_reason);
    }

    public function test_operator_accept_is_logged_without_fabricating_other_events(): void
    {
        [, $booking] = $this->pendingRental();

        $booking->update(['status' => BoatBookingStatus::Confirmed]);

        $events = $booking->fresh()->activities()->pluck('event')->all();

        $this->assertSame(['submitted', 'operator_accepted'], $events);
        $this->assertSame(
            'Boat Rental Request Approved',
            $booking->fresh()->activities()->where('event', 'operator_accepted')->value('title'),
        );
        $this->assertFalse(in_array('payment_verified', $events, true));
        $this->assertFalse(in_array('completed', $events, true));
    }

    public function test_admin_can_approve_pending_rental_on_behalf_of_operator(): void
    {
        Notification::fake();

        [$admin, $booking, $passenger, $operator] = $this->pendingRental(withPeople: true);

        $this->actingAs($admin)
            ->patch(route('boat-bookings.review', $booking), [
                'action' => 'approve',
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);
        $this->assertTrue($booking->activities()->where('event', 'admin_approved')->exists());
        $this->assertSame(
            $admin->name,
            $booking->activities()->where('event', 'admin_approved')->value('actor_name'),
        );

        Notification::assertSentTo($passenger, BookingStatusNotification::class);
        Notification::assertSentTo($operator, BookingStatusNotification::class);
    }

    public function test_show_records_missing_operator_approval_in_activity_history(): void
    {
        [$admin, $booking] = $this->pendingRental();

        $booking->forceFill(['status' => BoatBookingStatus::AwaitingPayment])->saveQuietly();

        $this->assertFalse($booking->activities()->where('event', 'operator_accepted')->exists());

        $this->actingAs($admin)
            ->get(route('bookings.requests.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Show')
                ->where('booking.activities.1.event', 'operator_accepted')
                ->where('booking.activities.1.title', 'Boat Rental Request Approved'));

        $this->assertTrue($booking->fresh()->activities()->where('event', 'operator_accepted')->exists());
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2?: User, 3?: User}
     */
    private function pendingRental(bool $withPeople = false): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Johnwell',
        ]);
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'name' => 'Captain Rico',
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
            'code' => 'BR-2026-00008',
            'customer_name' => 'Johnwell',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-10',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '4500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '900.00',
            'remaining_balance' => '3600.00',
        ]);

        if ($withPeople) {
            return [$admin, $booking, $passenger, $operator];
        }

        return [$admin, $booking];
    }
}
