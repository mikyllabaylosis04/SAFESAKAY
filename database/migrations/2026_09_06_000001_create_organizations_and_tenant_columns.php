<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('address');
            $table->string('contact_number', 30);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('boats', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('regular_trips', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'SafeSakay',
            'slug' => 'safesakay',
            'address' => 'Barangay Lakandula, Siargao',
            'contact_number' => '09000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        DB::table('boats')->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        DB::table('regular_trips')->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        DB::table('boat_bookings')->whereNull('organization_id')->update(['organization_id' => $organizationId]);

        Schema::table('boats', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->unique('code');
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('regular_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('boats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::dropIfExists('organizations');
    }
};
