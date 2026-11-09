<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->json('safety_equipment')->nullable()->after('safety_information');
            $table->unsignedSmallInteger('life_jacket_quantity')->nullable()->after('safety_equipment');
        });
    }

    public function down(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->dropColumn(['safety_equipment', 'life_jacket_quantity']);
        });
    }
};
