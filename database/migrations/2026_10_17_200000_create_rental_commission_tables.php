<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_commission_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('commission_enabled')->default(false);
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->date('effective_date')->nullable();
            $table->timestamps();
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->boolean('commission_enabled')->default(false)->after('remaining_balance');
            $table->decimal('commission_percentage', 5, 2)->nullable()->after('commission_enabled');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('commission_percentage');
            $table->decimal('operator_net_amount', 10, 2)->nullable()->after('commission_amount');
        });

        Schema::create('rental_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->decimal('rental_amount', 10, 2);
            $table->decimal('commission_percentage', 5, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('operator_net_amount', 10, 2);
            $table->string('settlement_status', 40)->default('pending_settlement')->index();
            $table->string('gcash_reference', 80)->nullable();
            $table->string('settlement_proof_path')->nullable();
            $table->timestamp('settlement_submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'settlement_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_commissions');

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'commission_enabled',
                'commission_percentage',
                'commission_amount',
                'operator_net_amount',
            ]);
        });

        Schema::dropIfExists('organization_commission_settings');
    }
};
