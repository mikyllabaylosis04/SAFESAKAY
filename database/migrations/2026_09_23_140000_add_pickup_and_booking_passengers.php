<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->string('pickup_point')->nullable()->after('destination');
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->unsignedTinyInteger('age');
            $table->string('gender', 30);
            $table->string('contact_number', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_passengers');

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->dropColumn('pickup_point');
        });
    }
};
