<?php

namespace Tests\Feature;

use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_include_revenue_and_boat_backend_payloads(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Daryl Mae',
            'capacity' => 20,
            'service_type' => 'regular_trip',
        ]);

        $trip = RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-10-01',
            'departure_time' => '08:00',
            'passenger_count' => 1,
            'status' => RegularTripStatus::Completed,
        ]);

        TripPassenger::query()->create([
            'organization_id' => $admin->organization_id,
            'regular_trip_id' => $trip->id,
            'full_name' => 'Ana Reyes',
            'age' => 28,
            'gender' => 'Female',
            'contact_number' => '09171234567',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->has('revenueRecords')
                ->where('revenueRecords.0.customer', 'Ana Reyes')
                ->where('revenueRecords.0.type', 'regular')
                ->where('revenueRecords.0.boat_name', 'Daryl Mae')
                ->has('boatUsage', 1)
                ->where('boatUsage.0.name', 'Daryl Mae')
                ->where('boatUsage.0.regular_trips', 1)
                ->has('boatUsage.0.total_revenue')
                ->has('boatUsage.0.completed_trips'));
    }

    public function test_boat_report_csv_export_respects_filters(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Export Test Boat',
            'capacity' => 20,
            'service_type' => 'regular_trip',
        ]);

        RegularTrip::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'route' => 'Lakandula ↔ Placer',
            'direction' => 'Lakandula → Placer',
            'rotation_order' => 1,
            'trip_date' => '2026-10-01',
            'departure_time' => '08:00',
            'passenger_count' => 0,
            'status' => RegularTripStatus::Completed,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.export.boats', [
                'status' => 'all',
                'operator' => 'all',
                'search' => 'Export Test',
            ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Export Test Boat', $response->streamedContent());
    }
}
