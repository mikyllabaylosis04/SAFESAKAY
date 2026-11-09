<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RestoreBetitoRentalHistorySeeder extends Seeder
{
    /**
     * @var list<array{email: string, username: string, name: string}>
     */
    private const ACCOUNTS = [
        [
            'email' => 'abetito@gmail.com',
            'username' => 'abetito',
            'name' => 'Kimmy Betito',
        ],
        [
            'email' => 'aliahkimbetito1@gmail.com',
            'username' => 'aliahkimbetito1',
            'name' => 'Aliah Kim Betito',
        ],
    ];

    public function run(): void
    {
        $organization = Organization::query()->where('name', 'BARANGAY LAKANDULA')->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'organization_id' => $organization->id,
                    'name' => $account['name'],
                    'username' => $account['username'],
                    'password' => Hash::make($account['username']),
                    'role' => UserRole::Passenger,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            $linked = BoatBooking::query()
                ->where('organization_id', $organization->id)
                ->where(function ($query) use ($account): void {
                    $name = $account['name'];
                    $query->where('customer_name', $name)
                        ->orWhere('customer_name', 'like', $name.' %');
                })
                ->update(['user_id' => $user->id]);

            $this->command?->info("{$account['name']} ({$user->email}): linked {$linked} rental record(s).");
            $this->command?->info('Mobile login: '.$account['username'].' / '.$account['username']);
        }
    }
}
