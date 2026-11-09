<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_refund_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('rental_refund_requests', 'reference')) {
                $table->string('reference', 32)->nullable()->unique();
            }
            if (! Schema::hasColumn('rental_refund_requests', 'reason_category')) {
                $table->string('reason_category', 40)->nullable();
                $table->text('details')->nullable();
            }
            if (! Schema::hasColumn('rental_refund_requests', 'requested_amount')) {
                $table->unsignedInteger('requested_amount')->nullable();
                $table->unsignedInteger('amount_paid')->nullable();
            }
            if (! Schema::hasColumn('rental_refund_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('rental_refund_requests', 'provider_refund_id')) {
                $table->string('provider_refund_id', 80)->nullable();
            }
        });

        if (! Schema::hasTable('rental_refund_events')) {
            Schema::create('rental_refund_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rental_refund_request_id')->index();
                $table->string('event', 40);
                $table->string('title', 160);
                $table->text('detail')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name', 120)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_refund_events');

        Schema::table('rental_refund_requests', function (Blueprint $table): void {
            foreach (['reference', 'reason_category', 'details', 'requested_amount', 'amount_paid', 'rejection_reason', 'provider_refund_id'] as $column) {
                if (Schema::hasColumn('rental_refund_requests', $column)) {
                    if ($column === 'reference') {
                        $table->dropUnique(['reference']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
