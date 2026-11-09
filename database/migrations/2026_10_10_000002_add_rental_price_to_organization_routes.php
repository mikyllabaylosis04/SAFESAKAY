<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_routes', 'rental_price')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->decimal('rental_price', 10, 2)->nullable()->after('trip_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_routes', 'rental_price')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->dropColumn('rental_price');
            });
        }
    }
};
