<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('operator_no_show_reports', 'passenger_choice')) {
                $table->string('passenger_choice', 32)->nullable()->after('resolution');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'passenger_choice_at')) {
                $table->timestamp('passenger_choice_at')->nullable()->after('passenger_choice');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'passenger_choice_note')) {
                $table->text('passenger_choice_note')->nullable()->after('passenger_choice_at');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'preferred_booking_date')) {
                $table->date('preferred_booking_date')->nullable()->after('passenger_choice_note');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'preferred_start_time')) {
                $table->string('preferred_start_time', 8)->nullable()->after('preferred_booking_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operator_no_show_reports', function (Blueprint $table) {
            foreach ([
                'passenger_choice',
                'passenger_choice_at',
                'passenger_choice_note',
                'preferred_booking_date',
                'preferred_start_time',
            ] as $column) {
                if (Schema::hasColumn('operator_no_show_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
