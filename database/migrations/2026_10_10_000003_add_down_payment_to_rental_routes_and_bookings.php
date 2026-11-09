<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_routes', 'down_payment_percent')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->decimal('down_payment_percent', 5, 2)->nullable()->after('rental_price');
            });
        }

        Schema::table('boat_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('boat_bookings', 'rental_price')) {
                $table->decimal('rental_price', 10, 2)->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('boat_bookings', 'down_payment_percent')) {
                $table->decimal('down_payment_percent', 5, 2)->nullable()->after('rental_price');
            }
            if (! Schema::hasColumn('boat_bookings', 'down_payment_amount')) {
                $table->decimal('down_payment_amount', 10, 2)->nullable()->after('down_payment_percent');
            }
            if (! Schema::hasColumn('boat_bookings', 'remaining_balance')) {
                $table->decimal('remaining_balance', 10, 2)->nullable()->after('down_payment_amount');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_routes', 'down_payment_percent')) {
            Schema::table('organization_routes', function (Blueprint $table) {
                $table->dropColumn('down_payment_percent');
            });
        }

        Schema::table('boat_bookings', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('boat_bookings', 'rental_price') ? 'rental_price' : null,
                Schema::hasColumn('boat_bookings', 'down_payment_percent') ? 'down_payment_percent' : null,
                Schema::hasColumn('boat_bookings', 'down_payment_amount') ? 'down_payment_amount' : null,
                Schema::hasColumn('boat_bookings', 'remaining_balance') ? 'remaining_balance' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
