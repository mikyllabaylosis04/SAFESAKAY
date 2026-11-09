<?php

namespace Tests\Feature;

use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoatRentalDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_boat_form_lists_only_this_barangays_active_exclusive_destinations(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $this->exclusiveRoute($admin, 'Bangkay', active: false);
        $this->regularRoute($admin, 'Placer');
        $this->exclusiveRoute($other, 'Foreign Island');

        $this->actingAs($admin)
            ->get(route('boats.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('boats/Index')
                ->has('rentalDestinations', 1)
                ->where('rentalDestinations.0.id', $sagisi->id)
                ->where('rentalDestinations.0.destination', 'Sagisi'));
    }

    public function test_admin_can_assign_multiple_rental_destinations_in_one_save(): void
    {
        $admin = User::factory()->create();
        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $bangkay = $this->exclusiveRoute($admin, 'Bangkay');

        $this->actingAs($admin)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX-1',
                'capacity' => 12,
                'service_availability' => [
                    BoatService::RegularTrip->value,
                    BoatService::PrivateBooking->value,
                ],
                'rental_destination_ids' => [$sagisi->id, $bangkay->id],
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX-1')->first();

        $this->assertNotNull($boat);
        $this->assertTrue($boat->offers(BoatService::PrivateBooking));
        $this->assertEqualsCanonicalizing(
            [$sagisi->id, $bangkay->id],
            $boat->rentalDestinations()->pluck('organization_routes.id')->all(),
        );
    }

    public function test_edit_boat_keeps_previously_assigned_destinations(): void
    {
        $admin = User::factory()->create();
        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $bangkay = $this->exclusiveRoute($admin, 'Bangkay');
        $sabang = $this->exclusiveRoute($admin, 'Sabang');
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->rentalDestinations()->sync([$sagisi->id, $bangkay->id]);

        $this->actingAs($admin)
            ->get(route('boats.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boats.0.rental_destination_ids', [$bangkay->id, $sagisi->id]));

        $this->actingAs($admin)
            ->post(route('boats.update', $boat), [
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => $boat->capacity,
                'status' => $boat->status->value,
                'service_availability' => BoatService::values(),
                'rental_destination_ids' => [$sagisi->id, $sabang->id],
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $this->assertEqualsCanonicalizing(
            [$sagisi->id, $sabang->id],
            $boat->rentalDestinations()->pluck('organization_routes.id')->all(),
        );
    }

    public function test_regular_trip_routes_cannot_be_assigned_as_rental_destinations(): void
    {
        $admin = User::factory()->create();
        $regular = $this->regularRoute($admin, 'Placer');

        $this->actingAs($admin)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX-2',
                'capacity' => 12,
                'service_availability' => [BoatService::PrivateBooking->value],
                'rental_destination_ids' => [$regular->id],
                ...$this->operatorPayload(),
            ])
            ->assertSessionHasErrors('rental_destination_ids.0');
    }

    public function test_admins_cannot_assign_another_barangays_rental_destination(): void
    {
        $admin = User::factory()->create();
        $foreign = $this->exclusiveRoute(User::factory()->create(), 'Foreign Island');

        $this->actingAs($admin)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX-3',
                'capacity' => 12,
                'service_availability' => [BoatService::PrivateBooking->value],
                'rental_destination_ids' => [$foreign->id],
                ...$this->operatorPayload(),
            ])
            ->assertSessionHasErrors('rental_destination_ids.0');
    }

    public function test_disabling_private_rental_clears_assigned_destinations(): void
    {
        $admin = User::factory()->create();
        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'service_type' => BoatService::PrivateBooking->value,
            'service_availability' => [BoatService::PrivateBooking->value],
        ]);
        $boat->rentalDestinations()->sync([$sagisi->id]);

        $this->actingAs($admin)
            ->post(route('boats.update', $boat), [
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => $boat->capacity,
                'status' => $boat->status->value,
                'service_availability' => [BoatService::RegularTrip->value],
                'rental_destination_ids' => [$sagisi->id],
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $this->assertSame([], $boat->rentalDestinations()->pluck('organization_routes.id')->all());
        $this->assertFalse($boat->fresh()->offers(BoatService::PrivateBooking));
        $this->assertTrue(OrganizationRoute::query()->whereKey($sagisi->id)->exists());
    }

    public function test_mobile_boat_payload_includes_assigned_rental_destinations(): void
    {
        $admin = User::factory()->create();
        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'service_type' => BoatService::PrivateBooking->value,
            'service_availability' => [BoatService::PrivateBooking->value],
        ]);
        $boat->rentalDestinations()->sync([$sagisi->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonPath('data.0.rental_destinations.0.destination', 'Sagisi')
            ->assertJsonPath('data.0.rental_destinations.0.rental_price', 1500)
            ->assertJsonPath('data.0.rental_destinations.0.rental_price_label', '₱1,500.00')
            ->assertJsonPath('data.0.rental_destinations.0.down_payment_percent', 30)
            ->assertJsonPath('data.0.rental_destinations.0.down_payment_amount', 450)
            ->assertJsonPath('data.0.rental_destinations.0.remaining_balance', 1050)
            ->assertJsonPath('data.0.rental_destinations.0.location', 'Siargao Islands');
    }

    public function test_mobile_booking_must_use_an_assigned_rental_destination(): void
    {
        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);
        $sagisi = $this->exclusiveRoute($admin, 'Sagisi');
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->rentalDestinations()->sync([$sagisi->id]);

        $payload = [
            'boat_id' => $boat->id,
            'pickup_point' => 'Lakandula Port',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'passenger_count' => 2,
            'contact_number' => '09171234567',
        ];

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [...$payload, 'destination' => 'Cloud 9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                ...$payload,
                'destination' => 'Sagisi',
                'rental_price' => '9.00',
                'down_payment_amount' => '1.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.destination', 'Sagisi')
            ->assertJsonPath('data.rental_price', 1500)
            ->assertJsonPath('data.down_payment_percent', 30)
            ->assertJsonPath('data.down_payment_amount', 450)
            ->assertJsonPath('data.remaining_balance', 1050);

        $bookingId = BoatBooking::query()->latest('id')->value('id');

        $sagisi->update(['down_payment_percent' => 50, 'rental_price' => 2000]);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/bookings/'.$bookingId)
            ->assertOk()
            ->assertJsonPath('data.rental_price', 1500)
            ->assertJsonPath('data.down_payment_percent', 30)
            ->assertJsonPath('data.down_payment_amount', 450)
            ->assertJsonPath('data.remaining_balance', 1050);
    }

    private function exclusiveRoute(User $user, string $destination, bool $active = true): OrganizationRoute
    {
        $origin = $user->organization?->exclusiveRentalOrigin() ?? 'Barangay Lakandula';

        return OrganizationRoute::query()->create([
            'organization_id' => $user->organization_id,
            'name' => OrganizationRoute::makeName($origin, $destination),
            'origin' => $origin,
            'destination' => $destination,
            'trip_type' => OrganizationRouteType::ExclusiveBoatRental->value,
            'rental_price' => 1500,
            'down_payment_percent' => 30,
            'is_active' => $active,
            'sort_order' => 8,
        ]);
    }

    private function regularRoute(User $user, string $destination): OrganizationRoute
    {
        return OrganizationRoute::query()->create([
            'organization_id' => $user->organization_id,
            'name' => OrganizationRoute::makeName('Lakandula', $destination),
            'origin' => 'Lakandula',
            'destination' => $destination,
            'trip_type' => OrganizationRouteType::RegularTrip->value,
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }
}
