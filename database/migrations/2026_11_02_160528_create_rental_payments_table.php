<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations');

            $table->foreignId('boat_booking_id')
                ->constrained('boat_bookings');

            $table->string('provider', 30)->default('paymongo');
            $table->string('payment_type', 30);

            // Amounts in centavos
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

            $table->timestamps();

            $table->index(['boat_booking_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_payments');
    }
};
