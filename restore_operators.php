<?php

use App\Models\Boat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$file = __DIR__.'/storage/inertia-devtools/01M3X2M70NDFTHRH9YW97BDHD8.json';
$j = json_decode(file_get_contents($file), true);
$boats = $j['propValues']['boats'] ?? [];

$cols = Schema::getColumnListing('operators');
echo 'operator cols: '.implode(', ', $cols).PHP_EOL;

foreach ($boats as $boat) {
    $name = trim((string) ($boat['operator_name'] ?? ''));
    if ($name === '') {
        continue;
    }

    $boatId = (int) $boat['id'];
    $model = Boat::query()->withoutGlobalScope('organization')->find($boatId);
    if (! $model) {
        echo "missing boat {$boatId}\n";
        continue;
    }

    $parts = preg_split('/\s+/', $name) ?: [$name];
    $first = $parts[0] ?? $name;
    $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

    $existing = DB::table('operators')->where('boat_id', $boatId)->first();
    if ($existing) {
        echo "operator exists for boat {$boatId}\n";
        continue;
    }

    $payload = [
        'boat_id' => $boatId,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if (in_array('organization_id', $cols, true)) {
        $payload['organization_id'] = $model->organization_id;
    }
    if (in_array('full_name', $cols, true)) {
        $payload['full_name'] = $name;
    }
    if (in_array('first_name', $cols, true)) {
        $payload['first_name'] = $first;
    }
    if (in_array('last_name', $cols, true)) {
        $payload['last_name'] = $last;
    }
    if (in_array('name', $cols, true)) {
        $payload['name'] = $name;
    }
    if (in_array('contact_number', $cols, true)) {
        $payload['contact_number'] = '09'.str_pad((string) $boatId, 9, '0', STR_PAD_LEFT);
    }
    if (in_array('is_active', $cols, true)) {
        $payload['is_active'] = 1;
    }

    DB::table('operators')->insert($payload);
    echo "operator {$name} -> boat {$boatId}\n";
}

echo "DONE\n";
