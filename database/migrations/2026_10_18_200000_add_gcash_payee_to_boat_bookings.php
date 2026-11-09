<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'gcash_payee')) {
                $table->string('gcash_payee', 20)->nullable()->after('operator_net_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('boat_bookings', 'gcash_payee')) {
                $table->dropColumn('gcash_payee');
            }
        });
    }
};
