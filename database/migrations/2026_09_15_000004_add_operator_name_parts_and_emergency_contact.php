<?php

use App\Models\Operator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('full_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('emergency_contact_name')->nullable()->after('experience_years');
            $table->string('emergency_contact_number')->nullable()->after('emergency_contact_name');
        });

        foreach (DB::table('operators')->orderBy('id')->get() as $operator) {
            $parts = Operator::splitFullName($operator->full_name);

            DB::table('operators')->where('id', $operator->id)->update([
                'first_name' => $parts['first'] ?: null,
                'middle_name' => $parts['middle'] ?: null,
                'last_name' => $parts['last'] ?: null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'emergency_contact_name',
                'emergency_contact_number',
            ]);
        });
    }
};
