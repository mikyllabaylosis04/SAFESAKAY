<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_commission_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('settlement_due_days')->default(3)->after('effective_date');
        });

        Schema::table('rental_commissions', function (Blueprint $table) {
            $table->timestamp('payment_verified_at')->nullable()->after('operator_net_amount');
            $table->timestamp('settlement_due_at')->nullable()->after('payment_verified_at');
            $table->string('settlement_method', 20)->nullable()->after('settlement_status');
            $table->timestamp('cash_intended_at')->nullable()->after('settlement_submitted_at');
            $table->string('cash_receipt_number', 40)->nullable()->after('cash_intended_at');
            $table->date('cash_received_at')->nullable()->after('cash_receipt_number');
            $table->string('cash_remarks', 500)->nullable()->after('cash_received_at');
            $table->timestamp('settlement_reminder_day_1_sent_at')->nullable();
            $table->timestamp('settlement_reminder_day_2_sent_at')->nullable();
            $table->timestamp('marked_overdue_at')->nullable();
        });

        Schema::table('operators', function (Blueprint $table) {
            $table->timestamp('exclusive_rental_restricted_at')->nullable()->after('admin_use_only');
            $table->foreignId('exclusive_rental_restricted_by')->nullable()->after('exclusive_rental_restricted_at')->constrained('users')->nullOnDelete();
            $table->string('exclusive_rental_restriction_reason', 500)->nullable()->after('exclusive_rental_restricted_by');
        });

        Schema::create('rental_commission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rental_commission_id', 'created_at']);
        });

        $settings = DB::table('organization_commission_settings')
            ->pluck('settlement_due_days', 'organization_id');

        foreach (DB::table('rental_commissions')->whereNull('payment_verified_at')->orderBy('id')->get() as $row) {
            $booking = DB::table('boat_bookings')->where('id', $row->boat_booking_id)->first();

            if (! $booking) {
                continue;
            }

            $verifiedAt = $booking->payment_verified_at ?? $row->created_at;
            $days = (int) ($settings[$row->organization_id] ?? 3);
            $dueAt = Carbon::parse($verifiedAt)->addDays(max(1, $days))->endOfDay();

            DB::table('rental_commissions')->where('id', $row->id)->update([
                'payment_verified_at' => $verifiedAt,
                'settlement_due_at' => in_array($row->settlement_status, ['collected_with_rental', 'paid'], true)
                    ? null
                    : $dueAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_commission_events');

        Schema::table('operators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exclusive_rental_restricted_by');
            $table->dropColumn([
                'exclusive_rental_restricted_at',
                'exclusive_rental_restriction_reason',
            ]);
        });

        Schema::table('rental_commissions', function (Blueprint $table) {
            $table->dropColumn([
                'payment_verified_at',
                'settlement_due_at',
                'settlement_method',
                'cash_intended_at',
                'cash_receipt_number',
                'cash_received_at',
                'cash_remarks',
                'settlement_reminder_day_1_sent_at',
                'settlement_reminder_day_2_sent_at',
                'marked_overdue_at',
            ]);
        });

        Schema::table('organization_commission_settings', function (Blueprint $table) {
            $table->dropColumn('settlement_due_days');
        });
    }
};
