<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->unsignedSmallInteger('queue_order')->nullable()->after('rotation_order');
        });

        DB::table('regular_trips')
            ->whereNull('queue_order')
            ->where('rotation_order', '>', 0)
            ->update(['queue_order' => DB::raw('rotation_order')]);
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->dropColumn('queue_order');
        });
    }
};
