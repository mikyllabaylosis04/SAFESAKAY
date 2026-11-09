<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'operator_availability_confirmed_at')) {
                $table->timestamp('operator_availability_confirmed_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('boat_bookings', 'operator_availability_confirmed_by')) {
                $table->foreignId('operator_availability_confirmed_by')->nullable()->after('operator_availability_confirmed_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::create('operator_no_show_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('report_note')->nullable();
            $table->timestamp('reported_at');
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('resolution', 48)->nullable();
            $table->foreignId('replacement_boat_id')->nullable()->constrained('boats')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_no_show_reports');

        Schema::table('boat_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('boat_bookings', 'operator_availability_confirmed_by')) {
                $table->dropConstrainedForeignId('operator_availability_confirmed_by');
            }
            if (Schema::hasColumn('boat_bookings', 'operator_availability_confirmed_at')) {
                $table->dropColumn('operator_availability_confirmed_at');
            }
        });
    }
};
