<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('closure_requested_at')->nullable()->after('restore_requested_by');
            $table->string('closure_reason', 180)->nullable()->after('closure_requested_at');
            $table->text('closure_details')->nullable()->after('closure_reason');
            $table->foreignId('closure_requested_by')->nullable()->after('closure_details')->constrained('users')->nullOnDelete();
            $table->timestamp('closure_rejected_at')->nullable()->after('closure_requested_by');
            $table->string('closure_rejection_reason', 500)->nullable()->after('closure_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closure_requested_by');
            $table->dropColumn([
                'closure_requested_at',
                'closure_reason',
                'closure_details',
                'closure_rejected_at',
                'closure_rejection_reason',
            ]);
        });
    }
};
