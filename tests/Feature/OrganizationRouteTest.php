<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_route_management(): void
    {
        $this->get(route('routes.index'))->assertRedirect(route('login'));
    }

    public function test_admins_only_see_their_organization_routes(): void
    {
        $lakandula = $this->lakandulaAdmin();
        $other = User::factory()->create();

        OrganizationRoute::query()->create([
            'organization_id' => $other->organization_id,
            'name' => 'Dapa ↔ General Luna',
            'origin' => 'Dapa',
            'destination' => 'General Luna',
            'trip_type' => 'regular_public',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($lakandula)
            ->get(route('routes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('routes/Index')
                ->has('routes', 1)
                ->where('home_port', 'Lakandula')
                ->where('routes.0.name', 'Lakandula ↔ Placer')
                ->where('routes.0.origin', 'Lakandula')
                ->where('routes.0.destination', 'Placer')
                ->where('routes.0.trip_type', 'regular_public')
                ->where('routes.0.trip_type_label', 'Regular Trip')
                ->where('routes.0.rental_price', null));
    }

    public function test_admins_can_create_an_active_regular_route(): void
    {
        $admin = $this->lakandulaAdmin();

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'destination' => 'Dapa',
                'trip_type' => 'regular_public',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertDatabaseHas('organization_routes', [
            'organization_id' => $admin->organization_id,
            'name' => 'Lakandula ↔ Dapa',
            'origin' => 'Lakandula',
            'destination' => 'Dapa',
            'trip_type' => 'regular_public',
            'is_active' => 1,
        ]);
    }

    public function test_new_routes_use_the_organization_home_port_as_origin(): void
    {
        $admin = $this->lakandulaAdmin();

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'origin' => 'Typed Something Else',
                'destination' => 'General Luna',
                'trip_type' => 'regular_public',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertDatabaseHas('organization_routes', [
            'organization_id' => $admin->organization_id,
            'name' => 'Lakandula ↔ General Luna',
            'origin' => 'Lakandula',
            'destination' => 'General Luna',
        ]);
    }

    public function test_admins_can_set_regular_fare_and_custom_discounts(): void
    {
        $admin = $this->lakandulaAdmin();
        $route = $admin->organization?->routes()->where('name', 'Lakandula ↔ Placer')->first();

        $this->actingAs($admin)
            ->post(route('routes.update', $route), [
                'origin' => 'Lakandula',
                'destination' => 'Placer',
                'trip_type' => 'regular_public',
                'regular_fare' => '50.00',
                'student_discount_percent' => '15',
                'senior_discount_percent' => '25',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertDatabaseHas('organization_routes', [
            'id' => $route->id,
            'regular_fare' => 50.00,
            'student_discount_percent' => 15.00,
            'senior_discount_percent' => 25.00,
        ]);

        $this->actingAs($admin)
            ->get(route('routes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('routes.0.regular_fare', 50)
                ->where('routes.0.student_fare', 42.5)
                ->where('routes.0.senior_fare', 37.5)
                ->where('routes.0.student_discount_percent', 15)
                ->where('routes.0.senior_discount_percent', 25));
    }

    public function test_exclusive_rental_uses_the_organization_home_port(): void
    {
        $admin = $this->lakandulaAdmin();

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'origin' => 'Lakandula Port',
                'destination' => 'Naked Island',
                'trip_type' => 'private_booking',
                'rental_price' => '1500.00',
                'down_payment_percent' => '30',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertDatabaseHas('organization_routes', [
            'organization_id' => $admin->organization_id,
            'name' => 'Lakandula ↔ Naked Island',
            'origin' => 'Lakandula',
            'destination' => 'Naked Island',
            'trip_type' => 'private_booking',
            'rental_price' => 1500.00,
            'down_payment_percent' => 30,
            'is_active' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('routes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.regular', 1)
                ->where('stats.exclusive', 1)
                ->where('stats.total', 2));
    }

    public function test_legacy_routes_without_type_are_shown_as_regular_trip(): void
    {
        $admin = $this->lakandulaAdmin();

        $route = OrganizationRoute::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Pilar ↔ Dapa',
            'origin' => 'Pilar',
            'destination' => 'Dapa',
            'trip_type' => '',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->get(route('routes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('routes.1.id', $route->id)
                ->where('routes.1.trip_type', 'regular_public')
                ->where('routes.1.trip_type_label', 'Regular Trip'));
    }

    public function test_active_regular_routes_appear_in_add_regular_trip(): void
    {
        $admin = $this->lakandulaAdmin();

        OrganizationRoute::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Pilar ↔ Dapa',
            'origin' => 'Pilar',
            'destination' => 'Dapa',
            'trip_type' => 'regular_public',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        OrganizationRoute::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Pilar ↔ Private Island',
            'origin' => 'Pilar',
            'destination' => 'Private Island',
            'trip_type' => 'private_booking',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $this->actingAs($admin)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/RegularTripRotation')
                ->has('organizationRoutes', 2)
                ->where('organizationRoutes.0.name', 'Lakandula ↔ Placer')
                ->where('organizationRoutes.1.name', 'Pilar ↔ Dapa'));
    }

    public function test_exclusive_rental_requires_a_valid_rental_price(): void
    {
        $admin = $this->lakandulaAdmin();

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'destination' => 'Sagisi',
                'trip_type' => 'private_booking',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('rental_price');

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'destination' => 'Sagisi',
                'trip_type' => 'private_booking',
                'rental_price' => '-50',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('rental_price');

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'origin' => 'Pilar',
                'destination' => 'Dapa',
                'trip_type' => 'regular_public',
                'rental_price' => '999',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertDatabaseHas('organization_routes', [
            'organization_id' => $admin->organization_id,
            'destination' => 'Dapa',
            'trip_type' => 'regular_public',
            'rental_price' => null,
        ]);
    }

    public function test_admins_can_update_an_exclusive_rental_price(): void
    {
        $admin = $this->lakandulaAdmin();

        $route = OrganizationRoute::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Barangay Lakandula ↔ Sagisi',
            'origin' => 'Barangay Lakandula',
            'destination' => 'Sagisi',
            'trip_type' => 'private_booking',
            'rental_price' => 1200,
            'is_active' => true,
            'sort_order' => 8,
        ]);

        $this->actingAs($admin)
            ->post(route('routes.update', $route), [
                'origin' => 'Barangay Lakandula',
                'destination' => 'Sagisi',
                'trip_type' => 'private_booking',
                'rental_price' => '1500.00',
                'down_payment_percent' => '30',
                'is_active' => '1',
            ])
            ->assertRedirect(route('routes.index'));

        $this->assertEquals(1500.00, (float) $route->fresh()->rental_price);
        $this->assertEquals(30.00, (float) $route->fresh()->down_payment_percent);

        $this->actingAs($admin)
            ->get(route('routes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('routes.1.destination', 'Sagisi')
                ->where('routes.1.rental_price', 1500)
                ->where('routes.1.rental_price_label', '₱1,500.00')
                ->where('routes.1.down_payment_percent', 30)
                ->where('routes.1.down_payment_percent_label', '30%'));
    }

    public function test_exclusive_rental_down_payment_must_be_between_zero_and_one_hundred(): void
    {
        $admin = $this->lakandulaAdmin();

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'destination' => 'Sagisi',
                'trip_type' => 'private_booking',
                'rental_price' => '1500.00',
                'down_payment_percent' => '120',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('down_payment_percent');

        $this->actingAs($admin)
            ->post(route('routes.store'), [
                'destination' => 'Sagisi',
                'trip_type' => 'private_booking',
                'rental_price' => '1500.00',
                'down_payment_percent' => '-5',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('down_payment_percent');
    }

    public function test_admins_cannot_update_another_organization_route(): void
    {
        $admin = $this->lakandulaAdmin();
        $foreign = OrganizationRoute::query()->create([
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'Socorro ↔ Del Carmen',
            'origin' => 'Socorro',
            'destination' => 'Del Carmen',
            'trip_type' => 'regular_public',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->post(route('routes.update', $foreign), [
                'origin' => 'Hacked',
                'destination' => 'Route',
                'trip_type' => 'regular_public',
                'is_active' => '0',
            ])
            ->assertNotFound();
    }

    private function lakandulaAdmin(): User
    {
        $organization = Organization::factory()->create([
            'name' => 'Barangay Lakandula',
            'address' => 'Lakandula, Del Carmen, Siargao',
        ]);

        return User::factory()->create([
            'organization_id' => $organization->id,
        ]);
    }
}
