<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rental_payments', 'organization_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rental_payments ENGINE=InnoDB');
        }

        Schema::table('rental_payments', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->after('id')
                ->constrained('organizations');

            $table->foreignId('boat_booking_id')
                ->after('organization_id')
                ->constrained('boat_bookings');

            $table->string('provider', 30)->default('paymongo');
            $table->string('payment_type', 30);

            // All monetary amounts are in centavos.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('operator_amount')->default(0);
            $table->unsignedBigInteger('provider_fee')->default(0);

            $table->string('currency', 3)->default('PHP');
            $table->string('status', 30)->default('pending');

            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();

            $table->text('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->index(['boat_booking_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Intentionally left empty to protect existing payment records.
    }
};
