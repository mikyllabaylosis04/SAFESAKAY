<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RestoreCaicaiphamRentalHistorySeeder extends Seeder
{
    public const EMAIL = 'caicaipham04@gmail.com';

    public const USERNAME = 'caicaipham04';

    public function run(): void
    {
        $organization = Organization::query()->where('name', 'BARANGAY LAKANDULA')->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'organization_id' => $organization->id,
                'name' => 'Miky',
                'username' => self::USERNAME,
                'password' => Hash::make(self::USERNAME),
                'role' => UserRole::Passenger,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $linked = BoatBooking::query()
            ->where('organization_id', $organization->id)
            ->where(function ($query): void {
                $query->where('customer_name', 'Miky')
                    ->orWhere('customer_name', 'like', 'Miky %');
            })
            ->update(['user_id' => $user->id]);

        $this->command?->info("Miky ({$user->email}): linked {$linked} rental records.");
        $this->command?->info('Mobile login: '.self::USERNAME.' / '.self::USERNAME);
    }
}
