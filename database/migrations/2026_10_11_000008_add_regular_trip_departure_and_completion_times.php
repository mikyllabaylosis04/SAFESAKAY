<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('regular_trips', 'departed_at')) {
                $table->timestamp('departed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('regular_trips', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('departed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('regular_trips', 'departed_at') ? 'departed_at' : null,
                Schema::hasColumn('regular_trips', 'completed_at') ? 'completed_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
