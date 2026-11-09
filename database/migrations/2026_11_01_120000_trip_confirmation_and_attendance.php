<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmation_status')) {
                $table->string('trip_confirmation_status', 40)->nullable()->after('operator_availability_confirmed_by');
            }
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmed_at')) {
                $table->timestamp('trip_confirmed_at')->nullable()->after('trip_confirmation_status');
            }
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmed_by_user_id')) {
                $table->foreignId('trip_confirmed_by_user_id')->nullable()->after('trip_confirmed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmation_method')) {
                $table->string('trip_confirmation_method', 40)->nullable()->after('trip_confirmed_by_user_id');
            }
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmation_remarks')) {
                $table->text('trip_confirmation_remarks')->nullable()->after('trip_confirmation_method');
            }
            if (! Schema::hasColumn('boat_bookings', 'trip_confirmation_conflict')) {
                $table->boolean('trip_confirmation_conflict')->default(false)->after('trip_confirmation_remarks');
            }
            if (! Schema::hasColumn('boat_bookings', 'operator_cannot_operate_reason')) {
                $table->text('operator_cannot_operate_reason')->nullable()->after('trip_confirmation_conflict');
            }
            if (! Schema::hasColumn('boat_bookings', 'operator_meeting_point_arrived_at')) {
                $table->timestamp('operator_meeting_point_arrived_at')->nullable()->after('operator_cannot_operate_reason');
            }
            if (! Schema::hasColumn('boat_bookings', 'operator_meeting_point_arrived_by_user_id')) {
                $table->foreignId('operator_meeting_point_arrived_by_user_id')->nullable()->after('operator_meeting_point_arrived_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_passengers', 'attendance_status')) {
                $table->string('attendance_status', 40)->default('not_yet_arrived')->after('sort_order');
            }
            if (! Schema::hasColumn('booking_passengers', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable()->after('attendance_status');
            }
            if (! Schema::hasColumn('booking_passengers', 'no_show_recorded_at')) {
                $table->timestamp('no_show_recorded_at')->nullable()->after('checked_in_at');
            }
            if (! Schema::hasColumn('booking_passengers', 'no_show_recorded_by_user_id')) {
                $table->foreignId('no_show_recorded_by_user_id')->nullable()->after('no_show_recorded_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('booking_passengers', 'no_show_remarks')) {
                $table->text('no_show_remarks')->nullable()->after('no_show_recorded_by_user_id');
            }
            if (! Schema::hasColumn('booking_passengers', 'attendance_admin_note')) {
                $table->text('attendance_admin_note')->nullable()->after('no_show_remarks');
            }
            if (! Schema::hasColumn('booking_passengers', 'attendance_corrected_by_user_id')) {
                $table->foreignId('attendance_corrected_by_user_id')->nullable()->after('attendance_admin_note')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('booking_passengers', 'attendance_corrected_at')) {
                $table->timestamp('attendance_corrected_at')->nullable()->after('attendance_corrected_by_user_id');
            }
        });

        if (! Schema::hasTable('boat_booking_replacements')) {
            Schema::create('boat_booking_replacements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('original_boat_id')->nullable()->constrained('boats')->nullOnDelete();
                $table->foreignId('original_operator_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('replacement_boat_id')->nullable()->constrained('boats')->nullOnDelete();
                $table->foreignId('replacement_operator_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('pending_operator');
                $table->text('reason')->nullable();
                $table->string('initiated_confirmation_method', 40)->nullable();
                $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('operator_responded_at')->nullable();
                $table->text('operator_decline_reason')->nullable();
                $table->foreignId('admin_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('admin_approved_at')->nullable();
                $table->text('admin_notes')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('boat_booking_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_booking_activities', 'metadata')) {
                $table->json('metadata')->nullable()->after('actor_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('boat_booking_activities', function (Blueprint $table) {
            if (Schema::hasColumn('boat_booking_activities', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });

        Schema::dropIfExists('boat_booking_replacements');

        Schema::table('booking_passengers', function (Blueprint $table) {
            foreach ([
                'attendance_corrected_at',
                'attendance_corrected_by_user_id',
                'attendance_admin_note',
                'no_show_remarks',
                'no_show_recorded_by_user_id',
                'no_show_recorded_at',
                'checked_in_at',
                'attendance_status',
            ] as $column) {
                if (Schema::hasColumn('booking_passengers', $column)) {
                    if (str_ends_with($column, '_user_id')) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('boat_bookings', 'operator_meeting_point_arrived_by_user_id')) {
                $table->dropConstrainedForeignId('operator_meeting_point_arrived_by_user_id');
            }
            foreach ([
                'operator_meeting_point_arrived_at',
                'operator_cannot_operate_reason',
                'trip_confirmation_conflict',
                'trip_confirmation_remarks',
                'trip_confirmation_method',
                'trip_confirmed_at',
                'trip_confirmation_status',
            ] as $column) {
                if (Schema::hasColumn('boat_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('boat_bookings', 'trip_confirmed_by_user_id')) {
                $table->dropConstrainedForeignId('trip_confirmed_by_user_id');
            }
        });
    }
};
