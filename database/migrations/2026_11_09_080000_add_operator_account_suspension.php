<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            if (! Schema::hasColumn('operators', 'account_suspended_at')) {
                $table->timestamp('account_suspended_at')->nullable()->after('exclusive_rental_restriction_reason');
            }
            if (! Schema::hasColumn('operators', 'account_suspension_ends_at')) {
                $table->timestamp('account_suspension_ends_at')->nullable()->after('account_suspended_at');
            }
            if (! Schema::hasColumn('operators', 'account_suspension_reason')) {
                $table->string('account_suspension_reason', 255)->nullable()->after('account_suspension_ends_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn([
                'account_suspended_at',
                'account_suspension_ends_at',
                'account_suspension_reason',
            ]);
        });
    }
};
