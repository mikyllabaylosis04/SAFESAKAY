<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passenger_no_show_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('passenger_no_show_reports', 'evidence_photo_path')) {
                $table->string('evidence_photo_path')->nullable()->after('operator_remarks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('passenger_no_show_reports', function (Blueprint $table): void {
            if (Schema::hasColumn('passenger_no_show_reports', 'evidence_photo_path')) {
                $table->dropColumn('evidence_photo_path');
            }
        });
    }
};
