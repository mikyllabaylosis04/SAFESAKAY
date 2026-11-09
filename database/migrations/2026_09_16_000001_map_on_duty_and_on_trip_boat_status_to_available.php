<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('boats')->whereIn('status', ['on_duty', 'on_trip'])->update([
            'status' => 'available',
        ]);
    }

    public function down(): void
    {
        // Legacy on-duty / on-trip values cannot be restored.
    }
};
