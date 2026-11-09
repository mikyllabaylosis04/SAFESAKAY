<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('billing_enabled')->default(false);
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->string('gcash_account_name')->nullable();
            $table->string('gcash_qr_path')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('billed')->default(true);
            $table->decimal('monthly_fee_override', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('platform_monthly_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->date('period_start');
            $table->decimal('amount', 10, 2);
            $table->string('status', 40)->default('due')->index();
            $table->string('gcash_reference', 80)->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'period']);
            $table->index(['status', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_monthly_invoices');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('platform_subscription_settings');
    }
};
