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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassengerAccountFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_update_profile_with_phone_and_emergency_contact(): void
    {
        $passenger = $this->passenger();

        $this->actingAs($passenger, 'sanctum')
            ->patchJson('/api/me', [
                'name' => 'Maria Santos',
                'email' => 'maria@example.com',
                'address' => 'Purok 2, Lakandula',
                'contact_number' => '+63 917 123 4567',
                'emergency_contact_name' => 'Jose Santos',
                'emergency_contact_number' => '09181234567',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Maria Santos')
            ->assertJsonPath('user.contact_number', '09171234567')
            ->assertJsonPath('user.emergency_contact_name', 'Jose Santos')
            ->assertJsonPath('user.emergency_contact_number', '09181234567');
    }

    public function test_profile_rejects_invalid_phone_and_taken_email(): void
    {
        $passenger = $this->passenger();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($passenger, 'sanctum')
            ->patchJson('/api/me', [
                'name' => 'Maria',
                'email' => 'taken@example.com',
                'contact_number' => '12345',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'contact_number']);
    }

    public function test_passenger_can_upload_profile_photo(): void
    {
        Storage::fake('public');
        $passenger = $this->passenger();

        $response = $this->actingAs($passenger, 'sanctum')
            ->post('/api/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNotNull($response->json('user.picture_url'));
        Storage::disk('public')->assertExists($passenger->fresh()->picture_path);
    }

    public function test_register_saves_contact_number(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New Passenger',
            'email' => 'new@example.com',
            'contact_number' => '09170000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('user.contact_number', '09170000001');
    }

    public function test_feedback_only_after_completed_trip_and_only_once(): void
    {
        [$passenger, $booking] = $this->booking(BoatBookingStatus::Confirmed);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/feedback", ['rating' => 5])
            ->assertUnprocessable();

        $booking->update(['status' => BoatBookingStatus::Completed, 'completed_at' => now()]);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/feedback", [
                'rating' => 4,
                'tags' => ['Safe trip', 'Friendly operator'],
                'comment' => 'Nice ride.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/feedback", ['rating' => 5])
            ->assertUnprocessable();

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonPath('data.0.rating_average', 4)
            ->assertJsonPath('data.0.rating_count', 1);
    }

    public function test_other_passenger_cannot_rate_the_trip(): void
    {
        [, $booking] = $this->booking(BoatBookingStatus::Completed);
        $other = $this->passenger($booking->organization_id);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/feedback", ['rating' => 1])
            ->assertNotFound();
    }

    public function test_help_returns_policies_hotlines_and_barangay_contact(): void
    {
        [$passenger, $booking] = $this->booking(BoatBookingStatus::Confirmed);
        $booking->organization?->update(['contact_number' => '09170001111']);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('data.barangay.contact_number', '09170001111')
            ->assertJsonPath('data.policies.0.key', 'rental')
            ->assertJsonPath('data.hotlines.0.number', '09170001111')
            ->assertJsonFragment(['number' => '911']);
    }

    public function test_mark_all_notifications_read(): void
    {
        [$passenger] = $this->booking(BoatBookingStatus::Confirmed);

        $this->actingAs($passenger, 'sanctum')->getJson('/api/notifications')->assertOk();
        $this->assertGreaterThan(0, $passenger->fresh()->unreadNotifications()->count());

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/notifications/read-all')
            ->assertOk();

        $this->assertSame(0, $passenger->fresh()->unreadNotifications()->count());
    }

    private function passenger(?int $organizationId = null): User
    {
        $attributes = ['role' => UserRole::Passenger];
        if ($organizationId) {
            $attributes['organization_id'] = $organizationId;
        }

        return User::factory()->create($attributes);
    }

    /**
     * @return array{0: User, 1: BoatBooking}
     */
    private function booking(BoatBookingStatus $status): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $passenger = $this->passenger($admin->organization_id);
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
            'code' => 'RENT-FB-1',
            'customer_name' => $passenger->name,
            'package' => 'Island hopping',
            'destination' => 'Panglima',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::AwaitingPayment,
            'rental_price' => '2000.00',
            'down_payment_percent' => '50.00',
            'down_payment_amount' => '1000.00',
            'remaining_balance' => '1000.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
            'gcash_payee' => GcashPayee::Barangay,
        ]);
        $booking->update([
            'status' => $status,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
        ]);

        return [$passenger, $booking->fresh(['organization'])];
    }
}
