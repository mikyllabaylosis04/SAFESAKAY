<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RentalScheduleBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rental_schedule_lists_todays_and_upcoming_bookings(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'private_booking',
            'service_availability' => [BoatService::PrivateBooking->value],
        ]);

        $today = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00001',
            'customer_name' => 'Today Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-10-14',
            'start_time' => '10:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::AwaitingPayment,
        ]);

        $later = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00002',
            'customer_name' => 'Tomorrow Guest',
            'package' => 'Sunset cruise',
            'destination' => 'Naked Island',
            'booking_date' => '2026-10-17',
            'start_time' => '08:00',
            'passenger_count' => 6,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => 'BR-2026-00003',
            'customer_name' => 'Cancelled Guest',
            'package' => 'Cloud 9',
            'destination' => 'Cloud 9',
            'booking_date' => '2026-10-14',
            'start_time' => '14:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Cancelled,
        ]);

        $this->actingAs($admin)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->where('stats.upcoming', 1)
                ->where('stats.upcoming_total', 1)
                ->has('upcomingBookings', 1)
                ->where('upcomingBookings.0.id', $today->id)
                ->has('calendarBookings', 2)
                ->where('calendarBookings.0.id', $today->id)
                ->where('calendarBookings.1.id', $later->id));
    }
}
