<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\Concerns\LoadsLakandulaUsersFromSnapshots;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RestoreLakandulaUserHistorySeeder extends Seeder
{
    use LoadsLakandulaUsersFromSnapshots;

    public const RESTORED_PASSWORD = 'password';

    public function run(): void
    {
        $this->call(EnsureLakandula707AdminSeeder::class);

        $organization = Organization::query()
            ->where('name', 'BARANGAY LAKANDULA')
            ->first();

        if (! $organization) {
            $this->command?->error('BARANGAY LAKANDULA organization not found.');

            return;
        }

        $rows = $this->loadMergedUsersFromSnapshots($organization->name);

        if ($rows === []) {
            $this->command?->error('No Lakandula users found in Inertia snapshots.');

            return;
        }

        $restored = 0;
        $linkedOperators = 0;
        $linkedBookings = 0;

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            $role = UserRole::tryFrom((string) ($row['role'] ?? '')) ?? UserRole::Passenger;

            if ($email === EnsureLakandula707AdminSeeder::ADMIN_EMAIL) {
                User::query()->where('email', $email)->update([
                    'organization_id' => $organization->id,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]);

                continue;
            }

            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '') {
                $username = User::uniqueUsernameFromEmail($email);
            }

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'organization_id' => $organization->id,
                    'name' => (string) ($row['name'] ?? $username),
                    'username' => $username,
                    'password' => Hash::make(self::RESTORED_PASSWORD),
                    'role' => $role,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'email_verified_at' => now(),
                ],
            );

            $restored++;

            if ($role->canonical() !== UserRole::Operator) {
                continue;
            }

            $boatId = $this->resolveBoatId($organization->id, $row);

            if ($boatId === null) {
                $boatLabel = (string) ($row['boat_label'] ?? 'n/a');
                $this->command?->warn("No boat match for operator {$email} ({$boatLabel}).");

                continue;
            }

            Operator::query()->where('user_id', $user->id)->update(['user_id' => null]);

            $updated = Operator::query()
                ->where('boat_id', $boatId)
                ->update(['user_id' => $user->id]);

            if ($updated > 0) {
                $linkedOperators++;
            }
        }

        $linkedBookings = $this->linkPassengerBookings($organization->id);

        $this->command?->info("Restored {$restored} users from snapshots (mobile password: ".self::RESTORED_PASSWORD.").");
        $this->command?->info("Linked {$linkedOperators} operator accounts to boats.");
        $this->command?->info("Linked {$linkedBookings} rental requests to mobile customer accounts.");
    }

    private function linkPassengerBookings(int $organizationId): int
    {
        $linked = 0;

        $users = User::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'name', 'email']);

        foreach (BoatBooking::query()->where('organization_id', $organizationId)->whereNull('user_id')->get() as $booking) {
            $needle = Str::lower(trim($booking->customer_name));
            if ($needle === '') {
                continue;
            }

            $match = $users->first(function (User $user) use ($needle): bool {
                $name = Str::lower(trim($user->name));
                if ($name === $needle) {
                    return true;
                }

                if ($name !== '' && str_starts_with($needle, $name.' ')) {
                    return true;
                }

                $emailLocal = Str::before(Str::lower($user->email), '@');

                return $name !== '' && (str_contains($needle, $name) || str_contains($name, $needle));
            });

            if ($match) {
                $booking->update(['user_id' => $match->id]);
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveBoatId(int $organizationId, array $row): ?int
    {
        $label = (string) ($row['boat_label'] ?? '');

        if ($label !== '' && preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $label, $matches)) {
            $code = trim($matches[2]);
            $name = trim($matches[1]);

            $byCode = Boat::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('code', $code)
                ->value('id');

            if ($byCode) {
                return (int) $byCode;
            }

            $byName = Boat::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('name', $name)
                ->value('id');

            if ($byName) {
                return (int) $byName;
            }
        }

        $legacyBoatId = (int) ($row['boat_id'] ?? 0);

        if ($legacyBoatId > 0) {
            $boat = Boat::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('id', $legacyBoatId)
                ->value('id');

            if ($boat) {
                return (int) $boat;
            }
        }

        return null;
    }
}
