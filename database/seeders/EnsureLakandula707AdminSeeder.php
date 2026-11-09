<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EnsureLakandula707AdminSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'lakandula707@gmail.com';

    public const ADMIN_PASSWORD = 'lakandula707';

    public function run(): void
    {
        $organization = Organization::query()
            ->where(function ($query): void {
                $query->where('name', 'BARANGAY LAKANDULA')
                    ->orWhere('slug', 'barangay-lakandula');
            })
            ->first();

        if (! $organization) {
            $organization = Organization::query()->create([
                'name' => 'BARANGAY LAKANDULA',
                'slug' => 'barangay-lakandula',
                'address' => 'Barangay Lakandula, Del Carmen, Surigao del Norte',
                'contact_number' => '09171234567',
                'is_active' => true,
                'approved_at' => now(),
            ]);
            $organization->seedDefaultRoute();
        } else {
            $organization->update([
                'is_active' => true,
                'approved_at' => $organization->approved_at ?? now(),
                'inactive_kind' => null,
                'suspended_at' => null,
                'suspended_reason' => null,
            ]);
        }

        $user = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if (! $user) {
            User::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Lakandula Admin',
                'email' => self::ADMIN_EMAIL,
                'username' => 'lakandula707',
                'password' => Hash::make(self::ADMIN_PASSWORD),
                'role' => UserRole::Administrator,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            return;
        }

        $user->update([
            'organization_id' => $organization->id,
            'username' => 'lakandula707',
            'password' => Hash::make(self::ADMIN_PASSWORD),
            'role' => UserRole::Administrator,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);
    }
}
