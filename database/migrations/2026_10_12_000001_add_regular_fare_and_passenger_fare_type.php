<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_routes', 'regular_fare')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->decimal('regular_fare', 10, 2)->nullable()->after('rental_price');
            });
        }

        if (! Schema::hasColumn('trip_passengers', 'fare_type')) {
            Schema::table('trip_passengers', function (Blueprint $table) {
                $table->string('fare_type')->default('regular')->after('age');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_routes', 'regular_fare')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->dropColumn('regular_fare');
            });
        }

        if (Schema::hasColumn('trip_passengers', 'fare_type')) {
            Schema::table('trip_passengers', function (Blueprint $table) {
                $table->dropColumn('fare_type');
            });
        }
    }
};
