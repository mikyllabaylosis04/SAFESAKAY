<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'passenger_no_show_admin_acknowledged_at')) {
                $table->timestamp('passenger_no_show_admin_acknowledged_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('boat_bookings', 'passenger_no_show_admin_acknowledged_by')) {
                $table->foreignId('passenger_no_show_admin_acknowledged_by')
                    ->nullable()
                    ->after('passenger_no_show_admin_acknowledged_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('boat_bookings', 'passenger_no_show_admin_acknowledged_by')) {
                $table->dropConstrainedForeignId('passenger_no_show_admin_acknowledged_by');
            }
            if (Schema::hasColumn('boat_bookings', 'passenger_no_show_admin_acknowledged_at')) {
                $table->dropColumn('passenger_no_show_admin_acknowledged_at');
            }
        });
    }
};
