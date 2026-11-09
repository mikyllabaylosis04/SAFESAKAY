<?php

$files = glob(__DIR__.'/storage/inertia-devtools/*.json');
rsort($files);

$best = null;
$bestScore = -1;

foreach ($files as $file) {
    $j = json_decode(file_get_contents($file), true);
    if (! is_array($j)) {
        continue;
    }

    $props = $j['page']['props'] ?? $j['props'] ?? null;
    if (! is_array($props)) {
        // sometimes nested differently
        $props = $j['__meta']['props'] ?? null;
    }

    // search recursively for trips arrays with many items
    $score = 0;
    $trips = null;
    $boats = null;
    $stack = [$j];
    while ($stack) {
        $node = array_pop($stack);
        if (! is_array($node)) {
            continue;
        }
        if (isset($node['trips']) && is_array($node['trips']) && isset($node['trips'][0]['boat_id'])) {
            $c = count($node['trips']);
            if ($c > $score) {
                $score = $c;
                $trips = $node['trips'];
                $boats = $node['boats'] ?? $node['tripBoats'] ?? null;
                $best = $file;
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            }
        }
    }

    if ($score > $bestScore) {
        $bestScore = $score;
    }
}

echo "bestFile=".($best ?? 'none').PHP_EOL;
echo "bestTrips={$bestScore}".PHP_EOL;

if ($best) {
    $j = json_decode(file_get_contents($best), true);
    $stack = [$j];
    $payload = null;
    while ($stack) {
        $node = array_pop($stack);
        if (! is_array($node)) {
            continue;
        }
        if (isset($node['trips']) && is_array($node['trips']) && count($node['trips']) === $bestScore) {
            $payload = $node;
            break;
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            }
        }
    }

    if ($payload) {
        file_put_contents(__DIR__.'/storage/recovered_rotation_payload.json', json_encode($payload, JSON_PRETTY_PRINT));
        echo 'boats='.count($payload['boats'] ?? []).PHP_EOL;
        echo 'tripBoats='.count($payload['tripBoats'] ?? []).PHP_EOL;
        echo 'trips='.count($payload['trips'] ?? []).PHP_EOL;
        echo 'auth='.json_encode($payload['auth']['user']['email'] ?? $payload['auth']['user'] ?? null).PHP_EOL;
        echo 'sampleTrip='.json_encode($payload['trips'][0] ?? null).PHP_EOL;
        echo 'sampleBoat='.json_encode(($payload['boats'][0] ?? $payload['tripBoats'][0] ?? null)).PHP_EOL;
    }
}

// also list boats pages
foreach ($files as $file) {
    $raw = file_get_contents($file);
    if (str_contains($raw, 'CAIX') || str_contains($raw, 'JAYAR') || str_contains($raw, 'lari')) {
        echo 'hit='.basename($file).' size='.strlen($raw).PHP_EOL;
    }
}
