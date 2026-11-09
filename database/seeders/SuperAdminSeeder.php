<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('safesakay.super_admin_email');
        $password = (string) env('SUPER_ADMIN_PASSWORD', 'SafeSakay@Super1');

        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => 'SafeSakay Super Admin',
            'username' => $user->username ?: User::uniqueUsernameFromEmail($email, $user->id),
            'role' => UserRole::SuperAdmin,
            'organization_id' => null,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = $password;
        }

        $user->save();
    }
}
