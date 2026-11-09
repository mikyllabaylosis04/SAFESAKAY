<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()->after('remaining_balance');
            }
            if (! Schema::hasColumn('boat_bookings', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('boat_bookings', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('boat_bookings', 'gcash_reference')) {
                $table->string('gcash_reference', 80)->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('boat_bookings', 'payment_receipt_path')) {
                $table->string('payment_receipt_path')->nullable()->after('gcash_reference');
            }
            if (! Schema::hasColumn('boat_bookings', 'payment_submitted_at')) {
                $table->timestamp('payment_submitted_at')->nullable()->after('payment_receipt_path');
            }
        });

        if (! Schema::hasTable('boat_booking_activities')) {
            Schema::create('boat_booking_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('boat_booking_id')->constrained('boat_bookings')->cascadeOnDelete();
                $table->string('event', 60);
                $table->string('title');
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_booking_activities');

        Schema::table('boat_bookings', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('boat_bookings', 'cancellation_reason') ? 'cancellation_reason' : null,
                Schema::hasColumn('boat_bookings', 'cancelled_by') ? 'cancelled_by' : null,
                Schema::hasColumn('boat_bookings', 'cancelled_at') ? 'cancelled_at' : null,
                Schema::hasColumn('boat_bookings', 'gcash_reference') ? 'gcash_reference' : null,
                Schema::hasColumn('boat_bookings', 'payment_receipt_path') ? 'payment_receipt_path' : null,
                Schema::hasColumn('boat_bookings', 'payment_submitted_at') ? 'payment_submitted_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
