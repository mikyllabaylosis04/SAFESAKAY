<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', UserRole::User->value)
            ->whereNotNull('username')
            ->update(['role' => UserRole::Passenger->value]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', UserRole::Passenger->value)
            ->update(['role' => UserRole::User->value]);
    }
};
