<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_no_show_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_no_show_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('strike_number');
            $table->date('incident_date');
            $table->string('booking_code', 32);
            $table->string('reason', 500)->default('Confirmed operator no-show');
            $table->foreignId('recorded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('operator_no_show_report_id');
            $table->index(['organization_id', 'operator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_no_show_violations');
    }
};
