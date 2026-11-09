<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tenant_id', 32)->nullable()->after('id');
        });

        foreach (DB::table('organizations')->orderBy('id')->get() as $organization) {
            do {
                $tenantId = 'SS-'.strtoupper(Str::random(8));
            } while (DB::table('organizations')->where('tenant_id', $tenantId)->exists());

            DB::table('organizations')->where('id', $organization->id)->update([
                'tenant_id' => $tenantId,
            ]);
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
