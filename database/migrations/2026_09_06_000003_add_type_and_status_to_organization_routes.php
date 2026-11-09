<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_routes', 'trip_type')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->string('trip_type')->default('regular_public')->after('destination');
            });
        }

        if (! Schema::hasColumn('organization_routes', 'is_active')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('trip_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('organization_routes', function (Blueprint $table) {
            $table->dropColumn(['trip_type', 'is_active']);
        });
    }
};
