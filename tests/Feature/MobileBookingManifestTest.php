<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BookingPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MobileBookingManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mobile_booking_stores_the_booker_and_passenger_count(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Santos',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'capacity' => 8,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Sagisi Beach',
                'pickup_point' => 'Lakandula Port',
                'purpose' => 'Island hopping',
                'booking_date' => '2026-09-24',
                'start_time' => '08:00',
                'passenger_count' => 4,
                'contact_number' => '09171234567',
                'remarks' => 'Bring life jackets',
            ])
            ->assertCreated()
            ->assertJsonPath('data.destination', 'Sagisi Beach')
            ->assertJsonPath('data.pickup_point', 'Lakandula Port')
            ->assertJsonPath('data.passenger_count', '4')
            ->assertJsonPath('data.passenger.full_name', 'Maria Santos')
            ->assertJsonPath('data.passenger.contact_number', '09171234567');

        $this->assertDatabaseHas('boat_bookings', [
            'user_id' => $passenger->id,
            'pickup_point' => 'Lakandula Port',
            'customer_name' => 'Maria Santos',
            'contact_number' => '09171234567',
            'passenger_count' => 4,
        ]);

        $this->assertSame(0, BookingPassenger::query()->count());
    }

    public function test_operator_sees_pending_rental_for_their_assigned_boat(): void
    {
        [$operator, $boat, $passenger] = $this->linkedOperatorBookingSetup();

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Sagisi Beach',
                'pickup_point' => 'Lakandula Port',
                'purpose' => 'Island hopping',
                'booking_date' => '2026-09-24',
                'start_time' => '16:00',
                'passenger_count' => 2,
                'contact_number' => '09171234567',
            ])
            ->assertCreated();

        $payload = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonFragment([
                'kind' => 'rental',
                'status' => 'pending',
                'customer_name' => 'Maria Santos',
            ])
            ->json('data');

        $this->assertTrue(
            collect($payload)->contains(
                fn (array $row) => (int) ($row['boat']['id'] ?? 0) === (int) $boat->id && $row['status'] === 'pending',
            ),
        );
    }

    public function test_unassigned_operator_does_not_see_other_boat_rentals(): void
    {
        [, $boat, $passenger] = $this->linkedOperatorBookingSetup();
        $this->createBooking($boat, $passenger, BoatBookingStatus::Pending);

        $otherOperator = User::factory()->create([
            'organization_id' => $boat->organization_id,
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($otherOperator, 'sanctum')
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mobile_booking_uses_the_next_free_code_when_00001_already_exists(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'capacity' => 8,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00001',
            'customer_name' => 'Existing Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-09-24',
            'start_time' => '06:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Sagisi Beach',
                'pickup_point' => 'Lakandula Port',
                'booking_date' => '2026-09-25',
                'start_time' => '08:00',
                'passenger_count' => 3,
                'contact_number' => '09171234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BR-2026-00002');
    }

    public function test_operator_can_complete_an_approved_booking(): void
    {
        [$operator, $boat, $passenger] = $this->linkedOperatorBookingSetup();
        $booking = $this->createBooking($boat, $passenger, BoatBookingStatus::Confirmed);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_raw', BoatBookingStatus::Completed->value);

        $this->assertSame(BoatBookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_operator_can_complete_an_approved_booking_via_patch(): void
    {
        [$operator, $boat, $passenger] = $this->linkedOperatorBookingSetup();
        $booking = $this->createBooking($boat, $passenger, BoatBookingStatus::Confirmed);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/bookings/{$booking->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_operator_cannot_complete_a_pending_booking(): void
    {
        [$operator, $boat, $passenger] = $this->linkedOperatorBookingSetup();
        $booking = $this->createBooking($boat, $passenger, BoatBookingStatus::Pending);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only an approved trip can be marked completed.');

        $this->assertSame(BoatBookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_passenger_cannot_complete_a_booking(): void
    {
        [, $boat, $passenger] = $this->linkedOperatorBookingSetup();
        $booking = $this->createBooking($boat, $passenger, BoatBookingStatus::Confirmed);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Boat, 2: User}
     */
    private function linkedOperatorBookingSetup(): array
    {
        $admin = User::factory()->create();
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Santos',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'capacity' => 8,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        return [$operator, $boat->fresh(['operator']), $passenger];
    }

    private function createBooking(Boat $boat, User $passenger, BoatBookingStatus $status): BoatBooking
    {
        return BoatBooking::query()->create([
            'organization_id' => $boat->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-00999',
            'customer_name' => $passenger->name,
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'pickup_point' => 'Lakandula Port',
            'booking_date' => '2026-09-24',
            'start_time' => '08:00',
            'passenger_count' => 3,
            'status' => $status,
        ]);
    }
}
