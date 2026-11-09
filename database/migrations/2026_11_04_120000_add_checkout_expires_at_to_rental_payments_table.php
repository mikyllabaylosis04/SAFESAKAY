<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_payments')) {
            return;
        }

        Schema::table('rental_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_payments', 'checkout_expires_at')) {
                $table->timestamp('checkout_expires_at')->nullable()->after('checkout_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rental_payments')) {
            return;
        }

        Schema::table('rental_payments', function (Blueprint $table) {
            if (Schema::hasColumn('rental_payments', 'checkout_expires_at')) {
                $table->dropColumn('checkout_expires_at');
            }
        });
    }
};
