<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('boat_id')
                ->constrained()
                ->nullOnDelete();
        });

        $users = DB::table('users')
            ->whereIn('role', [UserRole::Operator->value, UserRole::Staff->value])
            ->get(['id', 'name']);

        foreach ($users as $user) {
            DB::table('operators')
                ->whereNull('user_id')
                ->whereRaw('LOWER(full_name) = ?', [mb_strtolower(trim((string) $user->name))])
                ->update(['user_id' => $user->id]);
        }
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
