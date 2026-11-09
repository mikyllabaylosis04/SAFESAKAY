<?php

namespace Database\Seeders\Concerns;

use App\Enums\UserRole;

trait LoadsLakandulaUsersFromSnapshots
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function loadMergedUsersFromSnapshots(string $organizationName = 'BARANGAY LAKANDULA'): array
    {
        $dir = storage_path('inertia-devtools');
        $users = [];

        foreach (glob($dir.'/*.json') ?: [] as $file) {
            if (str_ends_with($file, '_meta.json')) {
                continue;
            }

            $payload = json_decode(file_get_contents($file), true);
            if (! is_array($payload)) {
                continue;
            }

            if (($payload['__meta']['component'] ?? '') !== 'users/Index') {
                continue;
            }

            foreach ($payload['propValues']['users'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $orgName = (string) ($row['organization_name'] ?? '');
                $role = UserRole::tryFrom((string) ($row['role'] ?? '')) ?? UserRole::Passenger;

                if ($orgName !== '' && $orgName !== $organizationName) {
                    if ($role->canonical() !== UserRole::Passenger) {
                        continue;
                    }
                }

                $users[$email] = array_merge($users[$email] ?? [], $row);
            }
        }

        return array_values($users);
    }
}
