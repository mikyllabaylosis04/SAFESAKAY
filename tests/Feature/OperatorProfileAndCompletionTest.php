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

class OperatorProfileAndCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_update_contact_and_emergency_but_not_registered_name(): void
    {
        [$operator, $boat] = $this->operatorWithBoat();

        $this->actingAs($operator, 'sanctum')
            ->patchJson('/api/me', [
                'name' => 'Changed Name',
                'email' => 'operator@example.com',
                'address' => 'Purok 5, Lakandula',
                'contact_number' => '09171112222',
                'emergency_contact_name' => 'Ana Operator',
                'emergency_contact_number' => '09183334444',
            ])
            ->assertOk()
            ->assertJsonPath('user.contact_number', '09171112222')
            ->assertJsonPath('user.operator.contact_number', '09171112222')
            ->assertJsonPath('user.operator.emergency_contact_name', 'Ana Operator')
            ->assertJsonPath('user.address', 'Purok 5, Lakandula');

        $this->assertSame('Boat Operator', $operator->fresh()->name);
        $this->assertSame('09183334444', $boat->operator->fresh()->emergency_contact_number);
    }

    public function test_operator_photo_updates_operator_profile_picture(): void
    {
        Storage::fake('public');
        [$operator, $boat] = $this->operatorWithBoat();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        $path = $boat->operator->fresh()->picture_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_completing_trip_records_unpaid_balance_as_paid_during_trip(): void
    {
        [$operator, $boat] = $this->operatorWithBoat();
        $booking = $this->confirmedBooking($boat);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/bookings/'.$booking->id)
            ->assertOk()
            ->assertJsonPath('data.can_complete', true)
            ->assertJsonPath('data.complete_settles_balance', true)
            ->assertJsonPath('data.complete_blocked_reason', null);

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/bookings/'.$booking->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.balance_status', 'paid')
            ->assertJsonPath('data.balance_method', 'cash');

        $booking->refresh();
        $this->assertSame($operator->id, $booking->balance_recorded_by);
        $this->assertNotNull($booking->balance_paid_at);
        $this->assertTrue($booking->activities()->where('event', 'balance_paid_on_trip')->exists());
    }

    /**
     * @return array{0: User, 1: Boat}
     */
    private function operatorWithBoat(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'name' => 'Boat Operator',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        return [$operator, $boat->fresh('operator')];
    }

    private function confirmedBooking(Boat $boat): BoatBooking
    {
        $passenger = User::factory()->create([
            'organization_id' => $boat->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $boat->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RENT-OP-1',
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
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'payment_verified_at' => now(),
        ]);

        return $booking->fresh();
    }
}
