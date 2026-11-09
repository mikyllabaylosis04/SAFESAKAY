<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regular_trips', function (Blueprint $table): void {
            if (! Schema::hasColumn('regular_trips', 'boarding_started_at')) {
                $table->timestamp('boarding_started_at')->nullable()->after('departed_at');
            }

            if (! Schema::hasColumn('regular_trips', 'boarding_wait_minutes')) {
                $table->unsignedSmallInteger('boarding_wait_minutes')->default(30)->after('boarding_started_at');
            }

            if (! Schema::hasColumn('regular_trips', 'boarding_wait_notified_at')) {
                $table->timestamp('boarding_wait_notified_at')->nullable()->after('boarding_wait_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table): void {
            foreach (['boarding_wait_notified_at', 'boarding_wait_minutes', 'boarding_started_at'] as $column) {
                if (Schema::hasColumn('regular_trips', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
