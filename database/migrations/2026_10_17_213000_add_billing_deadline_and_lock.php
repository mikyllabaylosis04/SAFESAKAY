<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('billing_locked_at')->nullable()->after('suspended_by');
            $table->string('billing_lock_reason', 500)->nullable()->after('billing_locked_at');
        });

        Schema::table('platform_monthly_invoices', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('period_start');
            $table->timestamp('warning_sent_at')->nullable()->after('rejection_reason');
            $table->timestamp('locked_at')->nullable()->after('warning_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_monthly_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_on', 'warning_sent_at', 'locked_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['billing_locked_at', 'billing_lock_reason']);
        });
    }
};
