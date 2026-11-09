<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\RegularTripStatus;
use App\Enums\UserRole;
use App\Enums\WeatherHoldReason;
use App\Enums\WeatherSuspensionStatus;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use App\Models\User;
use App\Models\WeatherSuspension;
use App\Notifications\AnnouncementNotification;
use App\Notifications\BookingStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeatherSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_suspends_trips_and_places_upcoming_rentals_on_weather_hold(): void
    {
        Notification::fake();

        [$admin, $affected, $other, $passenger, $completed] = $this->seedRentals();
        $going = $this->goingTrip($admin, RegularTripStatus::Scheduled);
        $departed = $this->goingTrip($admin, RegularTripStatus::OnTrip, 'DARYL');
        $historic = $this->goingTrip($admin, RegularTripStatus::Completed, 'DONE');

        $this->actingAs($admin)
            ->post(route('weather-suspensions.store'), $this->payload())
            ->assertRedirect();

        $suspension = WeatherSuspension::query()->first();
        $this->assertNotNull($suspension);
        $this->assertSame(WeatherSuspensionStatus::Active, $suspension->status);
        $this->assertSame(WeatherHoldReason::BadWeather, $suspension->reason);

        $this->assertSame(BoatBookingStatus::WeatherHold, $affected->fresh()->status);
        $this->assertSame(BookingPaymentStatus::Verified, $affected->fresh()->paymentStatus());
        $this->assertSame('GCASH-HOLD-1', $affected->fresh()->gcash_reference);
        $this->assertSame('2026-10-14', $affected->fresh()->original_booking_date?->toDateString());
        $this->assertSame(BoatBookingStatus::Confirmed, $other->fresh()->status);
        $this->assertSame(BoatBookingStatus::Completed, $completed->fresh()->status);

        $this->assertSame($suspension->id, $going->fresh()->weather_suspension_id);
        $this->assertFalse((bool) $going->fresh()->weather_review_required);
        $this->assertTrue((bool) $departed->fresh()->weather_review_required);
        $this->assertNull($historic->fresh()->weather_suspension_id);
        $this->assertSame(RegularTripStatus::Completed, $historic->fresh()->status);

        Notification::assertSentTo($passenger, BookingStatusNotification::class);
        Notification::assertSentTo($passenger, AnnouncementNotification::class);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('weatherAlert.headline', 'No trips due to Bad Weather')
                ->where('weatherAlert.reason', 'bad_weather'));

        $this->actingAs($admin)
            ->get(route('schedules.regular-trip-rotation'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('schedules/RegularTripRotation')
                ->where('weatherAlert.headline', 'No trips due to Bad Weather'));

        $this->actingAs($admin)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->where('weatherAlert.headline', 'No trips due to Bad Weather')
                ->where('weatherHoldDates.0.affected_date', '2026-10-14')
                ->where('weatherHoldDates.0.tone', 'red')
                ->where('weatherHoldDates.1.affected_date', '2026-10-15')
                ->where('weatherHoldDates.1.tone', 'yellow')
                ->where('weatherHoldDates.1.can_book', false));
    }

    public function test_watch_day_blocks_new_bookings_then_turns_red_if_hold_is_not_lifted(): void
    {
        [$admin, , , $passenger, , $boat] = $this->seedRentals();

        $watchBooking = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RNT-030',
            'customer_name' => 'Aliyah',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-15',
            'start_time' => '10:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
        ]);

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $this->actingAs($passenger, 'sanctum')
            ->postJson('/api/bookings', [
                'boat_id' => $boat->id,
                'destination' => 'Sagisi',
                'pickup_point' => 'Dapa pier',
                'purpose' => 'Leisure',
                'booking_date' => '2026-10-15',
                'start_time' => '14:00',
                'passenger_count' => 2,
                'contact_number' => '09170001111',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_date')
            ->assertJsonFragment(['message' => 'Weather Watch — Booking Temporarily Unavailable on Thursday, October 15, 2026. Due to uncertain weather conditions, boat rental bookings for the affected schedule are temporarily unavailable. Please select another available schedule or check again once the barangay confirms that travel conditions are safe.']);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/weather-advisories')
            ->assertOk()
            ->assertJsonPath('data.0.tone', 'red')
            ->assertJsonPath('data.0.affected_date', '2026-10-14')
            ->assertJsonPath('data.1.tone', 'yellow')
            ->assertJsonPath('data.1.affected_date', '2026-10-15')
            ->assertJsonPath('data.1.can_book', false);

        $this->assertSame(1, BoatBooking::query()->whereDate('booking_date', '2026-10-15')->count());
        $this->assertSame(BoatBookingStatus::Pending, $watchBooking->fresh()->status);

        Carbon::setTestNow('2026-10-15 08:00:00');

        $this->actingAs($admin)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->where('weatherAlert.affected_date', '2026-10-15')
                ->where('weatherHoldDates.0.affected_date', '2026-10-14')
                ->where('weatherHoldDates.0.tone', 'red')
                ->where('weatherHoldDates.1.affected_date', '2026-10-15')
                ->where('weatherHoldDates.1.tone', 'red')
                ->where('weatherHoldDates.2.affected_date', '2026-10-16')
                ->where('weatherHoldDates.2.tone', 'yellow'));

        $this->assertSame(BoatBookingStatus::WeatherHold, $watchBooking->fresh()->status);
    }

    public function test_duplicate_suspension_is_rejected(): void
    {
        [$admin] = $this->seedRentals();

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload())->assertRedirect();
        $this->actingAs($admin)
            ->from(route('announcements.index'))
            ->post(route('weather-suspensions.store'), $this->payload())
            ->assertSessionHasErrors('affected_date');

        $this->assertSame(1, WeatherSuspension::query()->count());
    }

    public function test_passenger_reschedules_and_admin_approves_without_new_payment(): void
    {
        Notification::fake();

        [$admin, $affected, $other, $passenger] = $this->seedRentals();

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());
        $affected->refresh();

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule", [
                'booking_date' => '2026-10-17',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_raw', BoatBookingStatus::ReschedulePending->value)
            ->assertJsonPath('data.can_reschedule', true)
            ->assertJsonPath('data.payment_status', 'verified')
            ->assertJsonPath('data.gcash_reference', 'GCASH-HOLD-1');

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule-review", [
                'action' => 'approve',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('boat-bookings.reschedule', $affected), [
                'action' => 'approve',
            ])
            ->assertRedirect();

        $affected->refresh();
        $this->assertSame(BoatBookingStatus::Confirmed, $affected->status);
        $this->assertSame('2026-10-17', $affected->booking_date->toDateString());
        $this->assertSame('2026-10-14', $affected->original_booking_date?->toDateString());
        $this->assertSame(BookingPaymentStatus::Verified, $affected->paymentStatus());
        $this->assertSame('GCASH-HOLD-1', $affected->gcash_reference);
        $this->assertSame('approved', $affected->reschedule_status);
        $this->assertSame(BoatBookingStatus::Confirmed, $other->fresh()->status);
    }

    public function test_assigned_operator_can_approve_weather_reschedule(): void
    {
        [$admin, $affected, , $passenger, , $boat] = $this->seedRentals();
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule", [
                'booking_date' => '2026-10-17',
            ])
            ->assertOk();

        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/bookings/{$affected->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'reschedule_pending')
            ->assertJsonPath('data.can_review_reschedule', true)
            ->assertJsonPath('data.requested_booking_date', '2026-10-17');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule-review", [
                'action' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.booking_date', '2026-10-17');
    }

    public function test_operator_linked_by_user_id_can_approve_when_has_one_operator_is_unlinked(): void
    {
        Notification::fake();

        [$admin, $affected, , $passenger, , $boat] = $this->seedRentals();
        $assigned = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $other = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);

        $boat->operator?->update(['user_id' => null]);
        Operator::query()->create([
            'boat_id' => $boat->id,
            'user_id' => $assigned->id,
            'first_name' => 'Bading',
            'last_name' => 'Operator',
            'full_name' => 'Bading Operator',
            'age' => 40,
            'gender' => 'Male',
            'contact_number' => '09123456789',
            'address' => 'Barangay Lakandula, Siargao',
        ]);

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule", [
                'booking_date' => '2026-10-17',
            ])
            ->assertOk();

        Notification::assertSentTo($assigned, BookingStatusNotification::class);
        Notification::assertNotSentTo($other, BookingStatusNotification::class);

        $this->actingAs($assigned, 'sanctum')
            ->getJson("/api/bookings/{$affected->id}")
            ->assertOk()
            ->assertJsonPath('data.can_review_reschedule', true);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/bookings/{$affected->id}")
            ->assertForbidden();

        $this->actingAs($assigned, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule-review", [
                'action' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_rejected_reschedule_stays_on_weather_hold(): void
    {
        [$admin, $affected, , $passenger] = $this->seedRentals();
        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$affected->id}/reschedule", ['booking_date' => '2026-10-17'])
            ->assertOk();

        $this->actingAs($admin)
            ->patch(route('boat-bookings.reschedule', $affected), [
                'action' => 'reject',
                'rejection_reason' => 'Boat already booked that day.',
            ])
            ->assertRedirect();

        $affected->refresh();
        $this->assertSame(BoatBookingStatus::WeatherHold, $affected->status);
        $this->assertSame('Boat already booked that day.', $affected->reschedule_rejection_reason);
        $this->assertSame(BookingPaymentStatus::Verified, $affected->paymentStatus());
    }

    public function test_regular_trip_cannot_depart_during_active_suspension(): void
    {
        [$admin] = $this->seedRentals();
        $going = $this->goingTrip($admin, RegularTripStatus::Scheduled);

        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $this->actingAs($admin)
            ->from(route('schedules.regular-trip-rotation'))
            ->patch(route('regular-trips.status.update', $going), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(RegularTripStatus::Scheduled, $going->fresh()->status);
    }

    public function test_lifting_suspension_does_not_restore_original_rental_dates(): void
    {
        [$admin, $affected] = $this->seedRentals();
        $this->actingAs($admin)->post(route('weather-suspensions.store'), $this->payload());

        $suspension = WeatherSuspension::query()->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('weather-suspensions.lift', $suspension), [
                'lift_notes' => 'Seas calmed after inspection.',
            ])
            ->assertRedirect();

        $this->assertSame(WeatherSuspensionStatus::Lifted, $suspension->fresh()->status);
        $this->assertSame(BoatBookingStatus::WeatherHold, $affected->fresh()->status);
        $this->assertSame('2026-10-14', $affected->fresh()->booking_date->toDateString());
    }

    public function test_route_options_list_each_place_once_grouped_by_trip_type(): void
    {
        [$admin] = $this->seedRentals();
        $admin->organization?->seedDefaultRoute();
        $this->rentalRoute($admin, 'sagisi');
        $this->rentalRoute($admin, 'Tanjog');

        $this->actingAs($admin)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('weatherRouteOptions', function ($options) {
                    $options = collect($options);
                    $values = $options->pluck('value')->map(fn ($value) => strtolower($value));

                    return $options->first()['value'] === 'all'
                        && $values->count() === $values->unique()->count()
                        && $options->pluck('label')->every(fn ($label) => ! str_contains($label, '↔'))
                        && $options->contains(fn ($option) => $option['label'] === 'Placer' && $option['group'] === 'Regular Trips')
                        && $options->contains(fn ($option) => $option['label'] === 'Sagisi' && $option['group'] === 'Private Rentals')
                        && $options->contains(fn ($option) => $option['label'] === 'Tanjog' && $option['group'] === 'Private Rentals');
                }));
    }

    public function test_place_only_selection_still_matches_trips_and_rentals(): void
    {
        Notification::fake();

        [$admin, $affected] = $this->seedRentals();
        $going = $this->goingTrip($admin, RegularTripStatus::Scheduled);

        $this->actingAs($admin)
            ->post(route('weather-suspensions.store'), [
                ...$this->payload(),
                'affected_routes' => ['Placer'],
            ])
            ->assertRedirect();

        $this->assertSame(WeatherSuspension::query()->firstOrFail()->id, $going->fresh()->weather_suspension_id);
        $this->assertSame(BoatBookingStatus::Confirmed, $affected->fresh()->status);

        WeatherSuspension::query()->firstOrFail()->update(['status' => WeatherSuspensionStatus::Lifted]);

        $this->actingAs($admin)
            ->post(route('weather-suspensions.store'), [
                ...$this->payload(),
                'affected_routes' => ['sagisi'],
            ])
            ->assertRedirect();

        $this->assertSame(BoatBookingStatus::WeatherHold, $affected->fresh()->status);
    }

    public function test_announcement_list_shows_posting_details_and_linked_suspension(): void
    {
        Notification::fake();

        [$admin] = $this->seedRentals();

        $this->actingAs($admin)
            ->post(route('weather-suspensions.store'), [
                ...$this->payload(),
                'affected_routes' => ['sagisi'],
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('announcements.index'))
            ->assertInertia(fn ($page) => $page
                ->where('announcements.0.author_name', $admin->name)
                ->where('announcements.0.posted_date_label', 'Oct 14, 2026')
                ->where('announcements.0.posted_time_label', '9:00 AM')
                ->where('announcements.0.status_label', 'Active')
                ->where('announcements.0.weather.routes_label', 'Sagisi')
                ->where('announcements.0.weather.affected_date_label', 'Wednesday, Oct 14, 2026')
                ->where('weatherSuspensions.0.affected_routes_label', 'Sagisi'));
    }

    private function rentalRoute(User $admin, string $destination): OrganizationRoute
    {
        return OrganizationRoute::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => OrganizationRoute::makeName('BARANGAY LAKANDULA', $destination),
            'origin' => 'BARANGAY LAKANDULA',
            'destination' => $destination,
            'trip_type' => OrganizationRouteType::ExclusiveBoatRental->value,
            'rental_price' => 1500,
            'down_payment_percent' => 30,
            'is_active' => true,
            'sort_order' => 8,
        ]);
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: BoatBooking, 3: User, 4: BoatBooking, 5: Boat}
     */
    private function seedRentals(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Aliyah',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);

        $affected = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RNT-025',
            'customer_name' => 'Aliyah',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-14',
            'start_time' => '09:00',
            'passenger_count' => 4,
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'down_payment_amount' => '500.00',
            'gcash_reference' => 'GCASH-HOLD-1',
            'payment_verified_at' => now()->subDay(),
        ]);

        $other = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RNT-026',
            'customer_name' => 'Aliyah',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-20',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Verified,
            'down_payment_amount' => '500.00',
            'gcash_reference' => 'GCASH-OTHER',
        ]);

        $completed = BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'user_id' => $passenger->id,
            'code' => 'RNT-010',
            'customer_name' => 'Aliyah',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-14',
            'start_time' => '06:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Completed,
            'payment_status' => BookingPaymentStatus::Verified,
            'down_payment_amount' => '500.00',
            'completed_at' => now()->subHours(3),
        ]);

        return [$admin, $affected, $other, $passenger, $completed, $boat];
    }

    private function goingTrip(User $admin, RegularTripStatus $status, string $code = 'ANGEL'): RegularTrip
    {
        $route = $admin->organization?->seedDefaultRoute();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => $code,
            'code' => $code,
            'service_type' => 'regular_trip',
        ]);

        return RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => $route?->name ?? 'Lakandula ↔ Placer',
            'direction' => ($route?->origin ?? 'Lakandula').' → '.($route?->destination ?? 'Placer'),
            'rotation_order' => 1,
            'cycle_number' => 1,
            'trip_date' => '2026-10-14',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => $status,
            'departed_at' => $status === RegularTripStatus::OnTrip ? now() : null,
            'completed_at' => $status === RegularTripStatus::Completed ? now()->subHour() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'reason' => 'bad_weather',
            'affected_date' => '2026-10-14',
            'affected_routes' => ['all'],
            'duration_type' => 'whole_day',
            'announcement_message' => 'All regular trips and exclusive boat rentals are suspended due to bad weather or rough seas.',
        ];
    }
}
