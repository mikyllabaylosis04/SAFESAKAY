<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\User;
use App\Services\ExpirePendingRentalBookingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpirePendingRentalBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_rental_older_than_two_days_is_auto_rejected(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $admin = User::factory()->create();
        $booking = $this->pendingBooking($admin, createdAt: '2026-10-17 11:00:00');

        $ids = app(ExpirePendingRentalBookingsService::class)->expire(now());

        $this->assertSame([$booking->id], $ids);
        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Rejected, $booking->status);
        $this->assertSame(ExpirePendingRentalBookingsService::AUTO_CANCEL_REASON, $booking->cancellation_reason);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_recent_pending_rental_is_not_expired(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $admin = User::factory()->create();
        $booking = $this->pendingBooking($admin, createdAt: '2026-10-19 12:00:00');

        $ids = app(ExpirePendingRentalBookingsService::class)->expire(now());

        $this->assertSame([], $ids);
        $this->assertSame(BoatBookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_artisan_command_expires_stale_pending_rentals(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $admin = User::factory()->create();
        $this->pendingBooking($admin, createdAt: '2026-10-15 08:00:00');

        $this->artisan('rentals:expire-pending')
            ->assertSuccessful();

        $this->assertDatabaseMissing('boat_bookings', [
            'status' => BoatBookingStatus::Pending->value,
        ]);
    }

    public function test_awaiting_payment_without_proof_older_than_two_days_is_auto_rejected(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');

        $admin = User::factory()->create();
        $booking = $this->awaitingPaymentBooking(
            $admin,
            updatedAt: '2026-10-27 11:00:00',
            paymentStatus: BookingPaymentStatus::Unpaid,
        );

        $ids = app(ExpirePendingRentalBookingsService::class)->expire(now());

        $this->assertSame([$booking->id], $ids);
        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Rejected, $booking->status);
        $this->assertSame(ExpirePendingRentalBookingsService::AUTO_PAYMENT_CANCEL_REASON, $booking->cancellation_reason);
    }

    public function test_recent_awaiting_payment_is_not_expired(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');

        $admin = User::factory()->create();
        $booking = $this->awaitingPaymentBooking(
            $admin,
            updatedAt: '2026-10-30 12:00:00',
            paymentStatus: BookingPaymentStatus::Unpaid,
        );

        $ids = app(ExpirePendingRentalBookingsService::class)->expire(now());

        $this->assertSame([], $ids);
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->fresh()->status);
    }

    public function test_awaiting_payment_with_proof_for_verification_is_not_expired(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');

        $admin = User::factory()->create();
        $booking = $this->awaitingPaymentBooking(
            $admin,
            updatedAt: '2026-10-20 08:00:00',
            paymentStatus: BookingPaymentStatus::ForVerification,
        );

        $ids = app(ExpirePendingRentalBookingsService::class)->expire(now());

        $this->assertSame([], $ids);
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->fresh()->status);
    }

    private function pendingBooking(User $admin, string $createdAt): BoatBooking
    {
        $boat = \App\Models\Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => User::factory()->create(['organization_id' => $admin->organization_id])->id,
            'code' => 'BR-2026-'.random_int(10000, 99999),
            'customer_name' => 'Auto Expire Test',
            'package' => 'Test',
            'destination' => 'Test',
            'booking_date' => now()->addWeek()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '1000.00',
        ]);

        $booking->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $booking;
    }

    private function awaitingPaymentBooking(
        User $admin,
        string $updatedAt,
        BookingPaymentStatus $paymentStatus,
    ): BoatBooking {
        $boat = \App\Models\Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => User::factory()->create(['organization_id' => $admin->organization_id])->id,
            'code' => 'BR-2026-'.random_int(10000, 99999),
            'customer_name' => 'Awaiting Payment Expire Test',
            'package' => 'Test',
            'destination' => 'Test',
            'booking_date' => now()->addWeek()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'payment_status' => $paymentStatus,
            'rental_price' => '1000.00',
        ]);

        $booking->forceFill(['created_at' => $updatedAt, 'updated_at' => $updatedAt])->save();

        return $booking;
    }
}
