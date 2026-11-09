<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoatServiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_boats_default_to_registered_unavailable_and_unassigned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX')->first();

        $this->assertNotNull($boat);
        $this->assertSame('Pump Boat', $boat->type);
        $this->assertSame(BoatRegistrationStatus::Registered, $boat->registration_status);
        $this->assertSame(BoatStatus::Unavailable, $boat->status);
        $this->assertSame([], $boat->selectedServices());
        $this->assertSame('unassigned', $boat->syncedServiceType());
        $this->assertFalse($boat->isEligibleForRegularTrip());
        $this->assertFalse($boat->isEligibleForPrivateBooking());
    }

    public function test_saved_boats_are_always_registered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                'registration_status' => BoatRegistrationStatus::PendingVerification->value,
                'status' => BoatStatus::Available->value,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $this->assertSame(
            BoatRegistrationStatus::Registered,
            Boat::query()->where('code', 'CAIX')->first()?->registration_status,
        );
    }

    public function test_edit_boat_keeps_saved_registration_operational_and_service_values(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'code' => 'KEEP-1',
            'registration_status' => BoatRegistrationStatus::Registered,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        $this->actingAs($user)
            ->post(route('boats.update', $boat), [
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => $boat->capacity,
                'registration_status' => BoatRegistrationStatus::Registered->value,
                'status' => BoatStatus::Maintenance->value,
                'service_availability' => [
                    BoatService::RegularTrip->value,
                    BoatService::PrivateBooking->value,
                ],
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat->refresh();

        $this->assertSame(BoatRegistrationStatus::Registered, $boat->registration_status);
        $this->assertSame(BoatStatus::Maintenance, $boat->status);
        $this->assertSame(BoatService::values(), $boat->selectedServices());
        $this->assertSame('both', $boat->syncedServiceType());
    }

    public function test_regular_trip_rotation_only_lists_registered_available_regular_boats(): void
    {
        $user = User::factory()->create();
        $eligible = $this->makeBoat($user, 'READY', BoatService::RegularTrip, BoatStatus::Available);
        $this->makeBoat($user, 'NEW', BoatService::RegularTrip, BoatStatus::Unavailable);
        $this->makeBoat($user, 'PRIVATE', BoatService::PrivateBooking, BoatStatus::Available);
        $this->makeBoat($user, 'MAINT', BoatService::RegularTrip, BoatStatus::Maintenance);

        $this->actingAs($user)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/RegularTripRotation')
                ->has('tripBoats', 1)
                ->where('tripBoats.0.id', $eligible->id));
    }

    public function test_private_booking_page_only_lists_registered_available_private_boats(): void
    {
        $user = User::factory()->create();
        $eligible = $this->makeBoat($user, 'PAKYAW', BoatService::PrivateBooking, BoatStatus::Available);
        $this->makeBoat($user, 'UNASSIGNED', null, BoatStatus::Unavailable);
        $this->makeBoat($user, 'REGULAR', BoatService::RegularTrip, BoatStatus::Available);
        $this->makeBoat($user, 'DOWN', BoatService::PrivateBooking, BoatStatus::Maintenance);

        $this->actingAs($user)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->has('availableBoats', 1)
                ->where('availableBoats.0.id', $eligible->id)
                ->where('availableBoats.0.is_open_for_booking', true));
    }

    public function test_unassigned_and_maintenance_boats_cannot_be_assigned_to_regular_trips(): void
    {
        $user = User::factory()->create();
        $route = $user->organization?->seedDefaultRoute();
        $unassigned = $this->makeBoat($user, 'NONE', null, BoatStatus::Unavailable);
        $maintenance = $this->makeBoat($user, 'SHOP', BoatService::RegularTrip, BoatStatus::Maintenance);

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->post(route('regular-trips.store'), $this->tripPayload($unassigned->id, $route?->name, $route?->origin, $route?->destination))
            ->assertRedirect(route('schedules.regular-trip-rotation'))
            ->assertSessionHasErrors('boat_id');

        $this->actingAs($user)
            ->from(route('schedules.regular-trip-rotation'))
            ->post(route('regular-trips.store'), $this->tripPayload($maintenance->id, $route?->name, $route?->origin, $route?->destination))
            ->assertRedirect(route('schedules.regular-trip-rotation'))
            ->assertSessionHasErrors('boat_id');
    }

    public function test_unassigned_boats_cannot_be_booked_privately(): void
    {
        $user = User::factory()->create();
        $unassigned = $this->makeBoat($user, 'NONE', null, BoatStatus::Unavailable);

        $this->actingAs($user)
            ->from(route('schedules.boat-booking-schedule'))
            ->post(route('boat-bookings.store'), [
                'boat_id' => $unassigned->id,
                'customer_name' => 'Guest',
                'destination' => 'Cloud 9',
                'booking_date' => now()->toDateString(),
                'start_time' => '09:00',
                'passenger_count' => 4,
                'status' => BoatBookingStatus::Confirmed->value,
            ])
            ->assertRedirect(route('schedules.boat-booking-schedule'))
            ->assertSessionHasErrors('boat_id');
    }

    private function makeBoat(
        User $user,
        string $code,
        ?BoatService $service,
        BoatStatus $status,
    ): Boat {
        $services = $service ? [$service->value] : [];

        return Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'code' => $code,
            'name' => $code,
            'registration_status' => BoatRegistrationStatus::Registered,
            'status' => $status,
            'service_type' => BoatService::legacyType($services),
            'service_availability' => $services,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tripPayload(int $boatId, ?string $route, ?string $origin, ?string $destination): array
    {
        return [
            'boat_id' => $boatId,
            'route' => $route,
            'direction' => $origin && $destination ? $origin.' → '.$destination : null,
            'trip_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'rotation_order' => 1,
        ];
    }
}
