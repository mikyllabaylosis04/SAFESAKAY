<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('declared_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('announcement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32)->unique();
            $table->string('reason', 32);
            $table->date('affected_date');
            $table->json('affected_routes');
            $table->string('duration_type', 32)->default('whole_day');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->text('announcement_message');
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('affected_trips_count')->default(0);
            $table->unsignedInteger('affected_rentals_count')->default(0);
            $table->unsignedInteger('review_flagged_count')->default(0);
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lift_notes', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'affected_date', 'status']);
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->date('original_booking_date')->nullable()->after('booking_date');
            $table->foreignId('weather_suspension_id')->nullable()->after('remarks')->constrained('weather_suspensions')->nullOnDelete();
            $table->timestamp('weather_hold_at')->nullable()->after('weather_suspension_id');
            $table->string('weather_reason', 64)->nullable()->after('weather_hold_at');
            $table->boolean('weather_review_required')->default(false)->after('weather_reason');
            $table->string('reschedule_status', 32)->nullable()->after('weather_review_required');
            $table->date('requested_booking_date')->nullable()->after('reschedule_status');
            $table->string('reschedule_rejection_reason', 500)->nullable()->after('requested_booking_date');
        });

        Schema::create('booking_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weather_suspension_id')->nullable()->constrained()->nullOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 32)->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->boolean('payment_carried_forward')->default(true);
            $table->decimal('down_payment_amount', 10, 2)->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('regular_trips', function (Blueprint $table) {
            $table->foreignId('weather_suspension_id')->nullable()->after('remarks')->constrained('weather_suspensions')->nullOnDelete();
            $table->boolean('weather_review_required')->default(false)->after('weather_suspension_id');
        });
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weather_suspension_id');
            $table->dropColumn('weather_review_required');
        });

        Schema::dropIfExists('booking_reschedules');

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weather_suspension_id');
            $table->dropColumn([
                'original_booking_date',
                'weather_hold_at',
                'weather_reason',
                'weather_review_required',
                'reschedule_status',
                'requested_booking_date',
                'reschedule_rejection_reason',
            ]);
        });

        Schema::dropIfExists('weather_suspensions');
    }
};
