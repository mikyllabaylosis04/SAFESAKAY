<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('restore_requested_at')->nullable()->after('billing_lock_reason');
            $table->string('restore_request_reason', 500)->nullable()->after('restore_requested_at');
            $table->foreignId('restore_requested_by')->nullable()->after('restore_request_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restore_requested_by');
            $table->dropColumn(['restore_requested_at', 'restore_request_reason']);
        });
    }
};
