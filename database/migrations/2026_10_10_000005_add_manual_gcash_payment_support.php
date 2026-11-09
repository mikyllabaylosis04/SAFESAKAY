<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'gcash_account_name')) {
                $table->string('gcash_account_name', 120)->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('organizations', 'gcash_qr_path')) {
                $table->string('gcash_qr_path')->nullable()->after('gcash_account_name');
            }
        });

        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'payment_status')) {
                $table->string('payment_status', 40)->default('unpaid')->after('remaining_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('organizations', 'gcash_account_name') ? 'gcash_account_name' : null,
                Schema::hasColumn('organizations', 'gcash_qr_path') ? 'gcash_qr_path' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('boat_bookings', 'payment_status')) {
            Schema::table('boat_bookings', function (Blueprint $table) {
                $table->dropColumn('payment_status');
            });
        }
    }
};
