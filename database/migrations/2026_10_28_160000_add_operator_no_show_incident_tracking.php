<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('operator_no_show_reports', 'snapshot_booking_date')) {
                $table->date('snapshot_booking_date')->nullable()->after('reported_at');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_status')) {
                $table->string('refund_status', 32)->nullable()->after('resolution');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_reference')) {
                $table->string('refund_reference', 80)->nullable()->after('refund_status');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_amount')) {
                $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_reference');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            foreach ([
                'snapshot_booking_date',
                'refund_status',
                'refund_reference',
                'refund_amount',
                'resolved_at',
            ] as $column) {
                if (Schema::hasColumn('operator_no_show_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
