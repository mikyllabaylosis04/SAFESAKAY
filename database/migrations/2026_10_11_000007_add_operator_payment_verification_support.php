<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'payment_verified_at')) {
                $table->timestamp('payment_verified_at')->nullable()->after('payment_submitted_at');
            }
            if (! Schema::hasColumn('boat_bookings', 'payment_verified_by')) {
                $table->foreignId('payment_verified_by')->nullable()->after('payment_verified_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('boat_bookings', 'payment_rejection_reason')) {
                $table->string('payment_rejection_reason', 500)->nullable()->after('payment_verified_by');
            }
            if (! Schema::hasColumn('boat_bookings', 'balance_status')) {
                $table->string('balance_status', 20)->default('unpaid')->after('payment_rejection_reason');
            }
            if (! Schema::hasColumn('boat_bookings', 'balance_method')) {
                $table->string('balance_method', 20)->nullable()->after('balance_status');
            }
            if (! Schema::hasColumn('boat_bookings', 'balance_reference')) {
                $table->string('balance_reference', 80)->nullable()->after('balance_method');
            }
            if (! Schema::hasColumn('boat_bookings', 'balance_paid_at')) {
                $table->timestamp('balance_paid_at')->nullable()->after('balance_reference');
            }
            if (! Schema::hasColumn('boat_bookings', 'balance_recorded_by')) {
                $table->foreignId('balance_recorded_by')->nullable()->after('balance_paid_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('boat_bookings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('balance_recorded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('boat_bookings', 'payment_verified_at') ? 'payment_verified_at' : null,
                Schema::hasColumn('boat_bookings', 'payment_verified_by') ? 'payment_verified_by' : null,
                Schema::hasColumn('boat_bookings', 'payment_rejection_reason') ? 'payment_rejection_reason' : null,
                Schema::hasColumn('boat_bookings', 'balance_status') ? 'balance_status' : null,
                Schema::hasColumn('boat_bookings', 'balance_method') ? 'balance_method' : null,
                Schema::hasColumn('boat_bookings', 'balance_reference') ? 'balance_reference' : null,
                Schema::hasColumn('boat_bookings', 'balance_paid_at') ? 'balance_paid_at' : null,
                Schema::hasColumn('boat_bookings', 'balance_recorded_by') ? 'balance_recorded_by' : null,
                Schema::hasColumn('boat_bookings', 'completed_at') ? 'completed_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
