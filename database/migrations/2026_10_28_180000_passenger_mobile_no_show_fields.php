<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('operator_no_show_reports', 'evidence_photo_path')) {
                $table->string('evidence_photo_path')->nullable()->after('report_note');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_gcash_account_name')) {
                $table->string('refund_gcash_account_name')->nullable()->after('refund_amount');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_gcash_mobile')) {
                $table->string('refund_gcash_mobile', 20)->nullable()->after('refund_gcash_account_name');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_gcash_qr_path')) {
                $table->string('refund_gcash_qr_path')->nullable()->after('refund_gcash_mobile');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_requested_at')) {
                $table->timestamp('refund_requested_at')->nullable()->after('refund_gcash_qr_path');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'reschedule_boat_id')) {
                $table->foreignId('reschedule_boat_id')->nullable()->after('preferred_start_time')->constrained('boats')->nullOnDelete();
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'reschedule_status')) {
                $table->string('reschedule_status', 32)->nullable()->after('reschedule_boat_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (Schema::hasColumn('operator_no_show_reports', 'reschedule_boat_id')) {
                $table->dropConstrainedForeignId('reschedule_boat_id');
            }
            foreach ([
                'evidence_photo_path',
                'refund_gcash_account_name',
                'refund_gcash_mobile',
                'refund_gcash_qr_path',
                'refund_requested_at',
                'reschedule_status',
            ] as $column) {
                if (Schema::hasColumn('operator_no_show_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
