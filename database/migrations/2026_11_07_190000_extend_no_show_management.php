<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('organizations', 'operator_no_show_grace_minutes')) {
                $table->unsignedSmallInteger('operator_no_show_grace_minutes')->default(15);
            }
            if (! Schema::hasColumn('organizations', 'passenger_no_show_grace_minutes')) {
                $table->unsignedSmallInteger('passenger_no_show_grace_minutes')->default(15);
            }
        });

        Schema::table('operator_no_show_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('operator_no_show_reports', 'report_reason')) {
                $table->string('report_reason', 32)->nullable()->after('report_note');
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'replacement_offer_status')) {
                $table->string('replacement_offer_status', 32)->nullable();
                $table->foreignId('replacement_offer_boat_id')->nullable()->constrained('boats')->nullOnDelete();
                $table->foreignId('replacement_offered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('replacement_offered_at')->nullable();
                $table->timestamp('replacement_responded_at')->nullable();
                $table->string('replacement_decline_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('operator_no_show_reports', 'rental_refund_request_id')) {
                $table->unsignedBigInteger('rental_refund_request_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('passenger_no_show_reports')) {
            Schema::create('passenger_no_show_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('boat_booking_id')->constrained('boat_bookings')->cascadeOnDelete();
                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('passenger_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
                $table->foreignId('boat_id')->nullable()->constrained('boats')->nullOnDelete();
                $table->timestamp('scheduled_departure_at')->nullable();
                $table->timestamp('reported_at');
                $table->text('operator_remarks')->nullable();
                $table->boolean('declaration_confirmed')->default(false);
                $table->string('cancellation_reason', 40)->default('passenger_no_show');
                $table->decimal('amount_paid', 10, 2)->default(0);
                $table->decimal('retained_amount', 10, 2)->default(0);
                $table->boolean('refund_eligible')->default(false);
                $table->string('status', 32)->default('confirmed');
                $table->text('dispute_reason')->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique('boat_booking_id');
                $table->index(['organization_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_no_show_reports');

        Schema::table('operator_no_show_reports', function (Blueprint $table): void {
            foreach (['replacement_offer_boat_id', 'replacement_offered_by_user_id'] as $foreign) {
                if (Schema::hasColumn('operator_no_show_reports', $foreign)) {
                    $table->dropConstrainedForeignId($foreign);
                }
            }
            foreach (['rental_refund_request_id', 'report_reason', 'replacement_offer_status', 'replacement_offered_at', 'replacement_responded_at', 'replacement_decline_reason'] as $column) {
                if (Schema::hasColumn('operator_no_show_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('organizations', function (Blueprint $table): void {
            foreach (['operator_no_show_grace_minutes', 'passenger_no_show_grace_minutes'] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
