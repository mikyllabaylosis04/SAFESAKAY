<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_checkout_policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version', 32);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['boat_booking_id', 'user_id', 'policy_version'], 'rental_policy_accept_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_checkout_policy_acceptances');
    }
};
