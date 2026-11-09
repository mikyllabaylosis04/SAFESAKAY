<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('stats.total_boats')
                ->has('stats.available_boats')
                ->has('stats.todays_regular_trips')
                ->has('stats.completed_regular_trips')
                ->has('stats.todays_passengers')
                ->has('stats.todays_private_rentals')
                ->has('stats.completed_private_rentals')
                ->has('stats.pending_rental_requests')
                ->has('stats.payments_for_verification')
                ->where('stats.total_boats', 0)
                ->where('stats.todays_passengers', 0));
    }
}
