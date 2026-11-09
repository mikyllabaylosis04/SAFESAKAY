<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_receipt_path')) {
                $table->string('refund_receipt_path')->nullable()->after('refund_reference');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_processed_by_user_id')) {
                $table->foreignId('refund_processed_by_user_id')
                    ->nullable()
                    ->after('refund_receipt_path')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'refund_processed_at')) {
                $table->timestamp('refund_processed_at')->nullable()->after('refund_processed_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (Schema::hasColumn('operator_no_show_reports', 'refund_processed_by_user_id')) {
                $table->dropConstrainedForeignId('refund_processed_by_user_id');
            }
            foreach (['refund_receipt_path', 'refund_processed_at'] as $column) {
                if (Schema::hasColumn('operator_no_show_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
