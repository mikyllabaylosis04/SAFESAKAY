<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use App\Support\RentalMobileUiPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RentalMobileUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_ui_hides_reminders_until_confirmed(): void
    {
        $booking = $this->bookingWithStatus(BoatBookingStatus::AwaitingPayment);

        $ui = RentalMobileUiPresenter::forBooking($booking);

        $this->assertFalse($ui['show_important_reminders']);
        $this->assertStringContainsString('barangay admin gcash', strtolower($ui['down_payment_instruction']));
    }

    public function test_rental_ui_shows_reminders_when_confirmed(): void
    {
        $booking = $this->bookingWithStatus(BoatBookingStatus::Confirmed);

        $this->assertTrue(RentalMobileUiPresenter::showImportantReminders($booking));
    }

    public function test_api_includes_rental_ui_and_operator_verification_notice(): void
    {
        Storage::fake('local');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->submitProof($passenger, $booking);

        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.rental_ui.show_important_reminders', false)
            ->assertJsonPath('data.rental_ui.rental_detail_notice.title', 'Down payment for verification')
            ->assertJsonPath('data.show_passenger_no_show_action', false)
            ->assertJsonPath('data.gcash_payee_label', 'SafeSakay');
    }

    private function bookingWithStatus(BoatBookingStatus $status): BoatBooking
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Passenger]);
        $boat = Boat::factory()->create([
            'organization_id' => $org->id,
            'status' => BoatStatus::OnDuty,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        return BoatBooking::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'boat_id' => $boat->id,
            'customer_name' => 'Test Passenger',
            'contact_number' => '09170000001',
            'booking_date' => now()->addDay(),
            'start_time' => '10:00:00',
            'destination' => 'Test Island',
            'package' => 'Test Island',
            'passenger_count' => 2,
            'status' => $status,
            'payment_status' => BookingPaymentStatus::Unpaid,
            'rental_price' => 1500,
            'down_payment_percent' => 30,
            'down_payment_amount' => 450,
            'remaining_balance' => 1050,
        ]);
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User}
     */
    private function linkedRental(): array
    {
        $org = Organization::factory()->create([
            'gcash_account_name' => 'Barangay GCash',
        ]);
        $operator = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::Operator,
        ]);
        $passenger = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::Passenger,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $org->id,
            'status' => BoatStatus::OnDuty,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $org->id,
            'user_id' => $passenger->id,
            'boat_id' => $boat->id,
            'customer_name' => $passenger->name,
            'contact_number' => '09170000002',
            'booking_date' => now()->addDays(3),
            'start_time' => '17:00:00',
            'destination' => 'bangkay',
            'package' => 'bangkay',
            'passenger_count' => 8,
            'status' => BoatBookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Unpaid,
            'rental_price' => 1500,
            'down_payment_percent' => 30,
            'down_payment_amount' => 450,
            'remaining_balance' => 1050,
        ]);

        return [$operator, $booking, $passenger];
    }

    private function submitProof(User $passenger, BoatBooking $booking): void
    {
        $operator = User::query()->find($booking->boat?->operator?->user_id);
        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASH-TEST-123456',
                'payment_amount' => '450',
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::ForVerification, $booking->paymentStatus());
    }
}
