<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_commissions', 'verified_downpayment')) {
                $table->decimal('verified_downpayment', 10, 2)->nullable()->after('operator_net_amount');
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_status')) {
                $table->string('operator_payout_status', 32)->default('on_hold')->after('verified_downpayment');
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_method')) {
                $table->string('operator_payout_method', 20)->nullable()->after('operator_payout_status');
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_at')) {
                $table->timestamp('operator_payout_at')->nullable()->after('operator_payout_method');
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_by')) {
                $table->foreignId('operator_payout_by')->nullable()->after('operator_payout_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_reference')) {
                $table->string('operator_payout_reference', 80)->nullable()->after('operator_payout_by');
            }
            if (! Schema::hasColumn('rental_commissions', 'operator_payout_remarks')) {
                $table->string('operator_payout_remarks', 500)->nullable()->after('operator_payout_reference');
            }
            if (! Schema::hasColumn('rental_commissions', 'commission_reversed_at')) {
                $table->timestamp('commission_reversed_at')->nullable()->after('operator_payout_remarks');
            }
        });

        $this->backfillDownpaymentCommissionAndPayoutStatus();
    }

    public function down(): void
    {
        Schema::table('rental_commissions', function (Blueprint $table) {
            if (Schema::hasColumn('rental_commissions', 'operator_payout_by')) {
                $table->dropConstrainedForeignId('operator_payout_by');
            }
            $columns = [
                'verified_downpayment',
                'operator_payout_status',
                'operator_payout_method',
                'operator_payout_at',
                'operator_payout_reference',
                'operator_payout_remarks',
                'commission_reversed_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('rental_commissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillDownpaymentCommissionAndPayoutStatus(): void
    {
        $service = app(\App\Services\RentalCommissionService::class);

        foreach (DB::table('rental_commissions')->orderBy('id')->get() as $row) {
            $booking = DB::table('boat_bookings')->where('id', $row->boat_booking_id)->first();
            if (! $booking) {
                continue;
            }

            $down = (float) ($booking->down_payment_amount ?? 0);
            $percent = (float) $row->commission_percentage;
            $updates = [];

            if ($down > 0) {
                $commissionAmount = $service->commissionAmount($down, $percent);
                $operatorNet = round($down - $commissionAmount, 2);
                $updates['verified_downpayment'] = number_format($down, 2, '.', '');
                $updates['commission_amount'] = number_format($commissionAmount, 2, '.', '');
                $updates['operator_net_amount'] = number_format($operatorNet, 2, '.', '');
            }

            $payee = $booking->gcash_payee ?? 'barangay';
            if ($payee === 'barangay' || $payee === 'Barangay') {
                if ($row->settlement_status === 'paid' && $row->verified_at) {
                    $updates['operator_payout_status'] = 'paid_out';
                    $updates['operator_payout_at'] = $row->verified_at;
                } elseif (in_array($booking->status, ['cancelled', 'rejected', 'passenger_no_show'], true)
                    || $row->settlement_status === 'waived') {
                    $updates['operator_payout_status'] = 'cancelled';
                } elseif ($booking->status === 'completed') {
                    $updates['operator_payout_status'] = 'ready_for_payout';
                } else {
                    $updates['operator_payout_status'] = 'on_hold';
                }
            }

            if ($updates !== []) {
                DB::table('rental_commissions')->where('id', $row->id)->update($updates);
            }
        }

        foreach (DB::table('boat_bookings')->where('commission_enabled', true)->whereNotNull('down_payment_amount')->get() as $booking) {
            $down = (float) $booking->down_payment_amount;
            if ($down <= 0) {
                continue;
            }
            $percent = (float) ($booking->commission_percentage ?? 0);
            $commissionAmount = $service->commissionAmount($down, $percent);
            $operatorNet = round($down - $commissionAmount, 2);
            DB::table('boat_bookings')->where('id', $booking->id)->update([
                'commission_amount' => number_format($commissionAmount, 2, '.', ''),
                'operator_net_amount' => number_format($operatorNet, 2, '.', ''),
            ]);
        }
    }
};
