<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\RentalRefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_page_includes_rental_payment_and_operator_insights(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $operator = User::factory()->create(['organization_id' => $admin->organization_id, 'role' => UserRole::Operator]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        $cancelled = $this->booking($admin, $boat, BoatBookingStatus::Cancelled, 'BR-2026-90001');
        $cancelled->update(['cancellation_reason' => 'Weather hold']);
        $this->booking($admin, $boat, BoatBookingStatus::Completed, 'BR-2026-90002');

        RentalRefundRequest::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_booking_id' => $cancelled->id,
            'type' => RentalRefundRequest::TYPE_PASSENGER_CANCELLATION,
            'status' => RentalRefundRequest::STATUS_PENDING_REVIEW,
            'requested_amount' => 200000,
            'amount_paid' => 200000,
        ]);

        $other = User::factory()->create(['role' => UserRole::Administrator]);
        $otherBoat = Boat::factory()->create(['organization_id' => $other->organization_id]);
        $this->booking($other, $otherBoat, BoatBookingStatus::Cancelled, 'BR-2026-90003');

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->has('incidentRecords', 1)
                ->where('incidentRecords.0.booking_code', 'BR-2026-90001')
                ->where('incidentRecords.0.cancelled_by', 'Weather')
                ->has('refundRecords', 1)
                ->where('refundRecords.0.status', RentalRefundRequest::STATUS_PENDING_REVIEW)
                ->where('refundRecords.0.amount_paid', 2000)
                ->has('payoutRecords')
                ->has('operatorActivity', 2)
                ->where('operatorActivity.1.completed', true));
    }

    private function booking(User $admin, Boat $boat, BoatBookingStatus $status, string $code): BoatBooking
    {
        return BoatBooking::query()->create([
            'organization_id' => $admin->organization_id,
            'boat_id' => $boat->id,
            'code' => $code,
            'customer_name' => 'Maria',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => $status,
            'rental_price' => '2000.00',
        ]);
    }
}
