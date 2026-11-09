<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boats', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedSmallInteger('capacity');
            $table->string('type');
            $table->string('license_no')->nullable();
            $table->date('registration_date')->nullable();
            $table->string('status')->default('available');
            $table->string('service_type')->default('regular_trip');
            $table->text('notes')->nullable();
            $table->string('picture_path')->nullable();
            $table->timestamps();
        });

        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('address')->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->string('picture_path')->nullable();
            $table->boolean('admin_use_only')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
        Schema::dropIfExists('boats');
    }
};
