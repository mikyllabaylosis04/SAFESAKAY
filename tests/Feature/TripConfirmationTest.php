<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\TripConfirmationStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BookingPassenger;
use App\Models\User;
use App\Notifications\TripConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TripConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_confirmation_request(): void
    {
        Notification::fake();
        [$admin, $booking, $operator] = $this->scheduledBooking(withOperator: true);
        $passenger = $booking->user;
        $this->assertNotNull($passenger);

        $this->actingAs($admin)
            ->post(route('boat-bookings.trip-confirmation.request', $booking))
            ->assertRedirect();

        Notification::assertSentTo($operator, TripConfirmationNotification::class);
        Notification::assertNotSentTo($passenger, TripConfirmationNotification::class);

        $this->assertDatabaseHas('boat_booking_activities', [
            'boat_booking_id' => $booking->id,
            'event' => 'trip_confirmation_requested',
        ]);
    }

    public function test_admin_can_confirm_trip_by_phone(): void
    {
        Notification::fake();
        [$admin, $booking] = $this->scheduledBooking();

        $this->actingAs($admin)
            ->post(route('boat-bookings.trip-confirmation.confirm', $booking), [
                'remarks' => 'Called operator — confirmed.',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(TripConfirmationStatus::Confirmed, $booking->trip_confirmation_status);
        $this->assertSame('phone_call', $booking->trip_confirmation_method?->value);

        $row = app(\App\Services\TripConfirmationService::class)->adminTripRow($booking);
        $this->assertTrue($row['confirmation_record']['is_read_only']);
        $this->assertSame('confirmed', $row['confirmation_record']['mode']);
        $this->assertSame('Available', $row['confirmation_record']['operator_response_label']);
        $this->assertSame('Admin', $row['confirmation_record']['confirmed_by_label']);
        $this->assertFalse($row['can_admin_confirm_trip']);
        $this->assertFalse($row['can_send_confirmation_request']);
    }

    public function test_method_label_shows_app_request_and_phone_when_both_used(): void
    {
        Notification::fake();
        [$admin, $booking] = $this->scheduledBooking(withOperator: true);

        $this->actingAs($admin)
            ->post(route('boat-bookings.trip-confirmation.request', $booking))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('boat-bookings.trip-confirmation.cannot-operate', $booking), [
                'reason' => 'Operator busy on another trip.',
            ])
            ->assertRedirect();

        $booking->refresh();
        $row = app(\App\Services\TripConfirmationService::class)->adminTripRow($booking);

        $this->assertSame('App request · Phone Call', $row['trip_confirmation_method_label']);
        $this->assertSame('Mobile App', $row['confirmation_record']['confirmation_request_via_label']);
        $this->assertSame('Phone Call', $row['confirmation_record']['confirmation_type_label']);
    }

    public function test_operator_scheduled_trips_lists_assigned_bookings(): void
    {
        $this->freezeManila('2026-10-14 08:00:00');
        [$admin, $booking, $operator] = $this->scheduledBooking(withOperator: true);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/scheduled-trips')
            ->assertOk()
            ->assertJsonPath('data.0.code', $booking->code)
            ->assertJsonPath('data.0.trip_confirmation_status', 'awaiting_confirmation');
    }

    public function test_passenger_no_show_blocked_before_waiting_period(): void
    {
        $this->freezeManila('2026-10-15 09:10:00');
        [$admin, $booking, $operator] = $this->scheduledBooking(withOperator: true);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/confirm-availability")
            ->assertOk();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/meeting-point-arrival")
            ->assertOk();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/passengers/no-show", [
                'passenger_ids' => [$booking->passengers()->first()->id],
            ])
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2?: User}
     */
    private function scheduledBooking(bool $withOperator = false): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
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
            'user_id' => User::factory()->create([
                'organization_id' => $admin->organization_id,
                'role' => UserRole::Passenger,
            ])->id,
            'code' => 'BR-2026-00999',
            'customer_name' => 'Rental Guest',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-15',
            'start_time' => '09:00',
            'passenger_count' => 1,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        BookingPassenger::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_booking_id' => $booking->id,
            'full_name' => 'Rental Guest',
            'age' => 28,
            'gender' => 'female',
            'sort_order' => 1,
        ]);

        if ($withOperator) {
            return [$admin, $booking, $operator];
        }

        return [$admin, $booking];
    }

    private function freezeManila(string $datetime): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse($datetime, 'Asia/Manila'));
    }
}
