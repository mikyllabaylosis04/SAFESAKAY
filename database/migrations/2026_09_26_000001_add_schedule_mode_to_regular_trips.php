<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->string('schedule_mode', 20)->default('timed')->after('rotation_order');
        });
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->dropColumn('schedule_mode');
        });
    }
};
