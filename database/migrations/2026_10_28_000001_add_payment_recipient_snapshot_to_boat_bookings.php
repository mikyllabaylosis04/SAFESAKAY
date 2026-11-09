<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'gcash_recipient_account_name')) {
                $table->string('gcash_recipient_account_name', 120)->nullable()->after('gcash_payee');
            }
            if (! Schema::hasColumn('boat_bookings', 'gcash_recipient_mobile')) {
                $table->string('gcash_recipient_mobile', 30)->nullable()->after('gcash_recipient_account_name');
            }
            if (! Schema::hasColumn('boat_bookings', 'payment_amount_submitted')) {
                $table->decimal('payment_amount_submitted', 12, 2)->nullable()->after('gcash_recipient_mobile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            foreach (['payment_amount_submitted', 'gcash_recipient_mobile', 'gcash_recipient_account_name'] as $column) {
                if (Schema::hasColumn('boat_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
