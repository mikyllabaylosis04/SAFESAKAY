<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\File;

trait LoadsLakandulaBookingsFromSnapshots
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function loadMergedBookingsFromSnapshots(): array
    {
        $cached = storage_path('lakandula-bookings-merged.json');

        if (File::isFile($cached)) {
            $decoded = json_decode(File::get($cached), true);

            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        $dir = storage_path('inertia-devtools');
        $bookings = [];

        foreach (glob($dir.'/*.json') ?: [] as $file) {
            if (str_ends_with($file, '_meta.json')) {
                continue;
            }

            $payload = json_decode(File::get($file), true);
            if (! is_array($payload)) {
                continue;
            }

            $component = $payload['__meta']['component'] ?? '';
            $props = $payload['propValues'] ?? [];

            if ($component === 'bookings/Requests' && isset($props['bookings']) && is_array($props['bookings'])) {
                foreach ($props['bookings'] as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id > 0) {
                        $bookings[$id] = $row;
                    }
                }
            }

            if ($component === 'bookings/Show' && isset($props['booking']['id'])) {
                $row = $props['booking'];
                $id = (int) $row['id'];
                $bookings[$id] = array_merge($bookings[$id] ?? [], $row);
            }
        }

        ksort($bookings);

        return array_values($bookings);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadSnapshotProps(string $relativePath): array
    {
        $path = storage_path($relativePath);

        if (! File::isFile($path)) {
            return [];
        }

        $payload = json_decode(File::get($path), true);

        return $payload['propValues']
            ?? $payload['http']['responseBody']['value']['props']
            ?? [];
    }
}
