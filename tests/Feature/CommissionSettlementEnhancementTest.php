<?php

namespace Tests\Feature;

use App\Enums\CommissionSettlementMethod;
use App\Enums\CommissionSettlementStatus;
use App\Enums\GcashPayee;
use App\Models\RentalCommission;
use App\Services\CommissionSettlementReminderService;
use App\Services\RentalCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class CommissionSettlementEnhancementTest extends RentalCommissionTest
{
    use RefreshDatabase;

    public function test_operator_can_declare_cash_without_marking_paid(): void
    {
        [, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: true, downPaymentPayee: GcashPayee::Operator);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/commissions/'.$commission->id.'/declare-cash')
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.status', 'pending_settlement');

        $fresh = $commission->fresh();
        $this->assertSame(CommissionSettlementMethod::Cash, $fresh->settlement_method);
        $this->assertNotSame(CommissionSettlementStatus::Paid, $fresh->settlement_status);
    }

    public function test_admin_records_cash_payment_with_receipt_number(): void
    {
        [$admin, $operator, , $booking] = $this->exclusiveRental(enabled: true, complete: true, downPaymentPayee: GcashPayee::Operator);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        app(RentalCommissionService::class)->declareCashSettlement($commission, $operator);

        $this->actingAs($admin)
            ->post(route('commissions.record-cash', $commission), [
                'received_at' => now()->toDateString(),
                'remarks' => 'Paid at barangay hall',
                'confirm_received' => true,
            ])
            ->assertRedirect();

        $fresh = $commission->fresh();
        $this->assertSame(CommissionSettlementStatus::Paid, $fresh->settlement_status);
        $this->assertNotNull($fresh->cash_receipt_number);
        $this->assertStringStartsWith('CS-', $fresh->cash_receipt_number);
    }

    public function test_reminder_service_marks_overdue_after_deadline(): void
    {
        Notification::fake();

        [, , , $booking] = $this->exclusiveRental(enabled: true, complete: true, downPaymentPayee: GcashPayee::Operator);
        $commission = RentalCommission::query()->where('boat_booking_id', $booking->id)->firstOrFail();

        $commission->update([
            'payment_verified_at' => now()->subDays(4),
            'settlement_due_at' => now()->subDay(),
            'settlement_reminder_day_1_sent_at' => now()->subDays(3),
            'settlement_reminder_day_2_sent_at' => now()->subDays(2),
        ]);

        app(CommissionSettlementReminderService::class)->process();

        $this->assertSame(CommissionSettlementStatus::Overdue, $commission->fresh()->settlement_status);
    }
}
