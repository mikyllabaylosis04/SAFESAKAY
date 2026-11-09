<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('boat_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->text('reason')->nullable();
            $table->unsignedInteger('refund_amount')->nullable()->comment('Centavos');
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('manual_reference', 100)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['boat_booking_id', 'status']);
        });

        Schema::table('rental_payments', function (Blueprint $table) {
            $table->string('refund_id', 100)->nullable()->after('paid_at');
            $table->string('refund_status', 32)->nullable()->after('refund_id');
            $table->unsignedInteger('refunded_amount')->nullable()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rental_payments', function (Blueprint $table) {
            $table->dropColumn(['refund_id', 'refund_status', 'refunded_amount', 'refunded_at']);
        });

        Schema::dropIfExists('rental_refund_requests');
    }
};
