<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temp_payment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('boat_booking_id')->nullable()->constrained('boat_bookings');
            $table->foreignId('rental_payment_id')->nullable()->unique();
            $table->string('transaction_id')->unique();
            $table->string('rental_reference', 80);
            $table->string('customer_name');
            $table->string('payment_method', 20);
            $table->string('payment_type', 30);
            $table->decimal('total_amount', 12, 2);
            $table->string('payment_reference')->nullable();
            $table->string('payment_status', 20);
            $table->string('source', 20)->default('live');
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'payment_status']);
            $table->index(['organization_id', 'payment_date']);
        });

        Schema::create('temp_commission_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('temp_payment_record_id')->unique()->constrained('temp_payment_records');
            $table->foreignId('boat_booking_id')->nullable()->constrained('boat_bookings');
            $table->string('commission_id')->unique();
            $table->string('rental_reference', 80);
            $table->string('customer_name');
            $table->decimal('total_rental_payment', 12, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('operator_share', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('source', 20)->default('live');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temp_commission_records');
        Schema::dropIfExists('temp_payment_records');
    }
};
