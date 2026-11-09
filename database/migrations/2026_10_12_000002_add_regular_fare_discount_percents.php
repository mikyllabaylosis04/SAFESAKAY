<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_routes', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_routes', 'student_discount_percent')) {
                $table->decimal('student_discount_percent', 5, 2)->nullable()->after('regular_fare');
            }

            if (! Schema::hasColumn('organization_routes', 'senior_discount_percent')) {
                $table->decimal('senior_discount_percent', 5, 2)->nullable()->after('student_discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_routes', function (Blueprint $table) {
            if (Schema::hasColumn('organization_routes', 'student_discount_percent')) {
                $table->dropColumn('student_discount_percent');
            }

            if (Schema::hasColumn('organization_routes', 'senior_discount_percent')) {
                $table->dropColumn('senior_discount_percent');
            }
        });
    }
};
