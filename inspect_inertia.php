<?php

$file = __DIR__.'/storage/inertia-devtools/01M3X2M70NDFTHRH9YW97BDHD8.json';
$j = json_decode(file_get_contents($file), true);

echo 'top keys: '.implode(', ', array_keys($j)).PHP_EOL;

function findPath(array $node, string $needle, string $path = ''): void
{
    foreach ($node as $k => $v) {
        $p = $path === '' ? (string) $k : $path.'.'.$k;
        if ($k === $needle) {
            echo "FOUND {$p} type=".gettype($v);
            if (is_array($v)) {
                echo ' count='.count($v);
                if (isset($v[0]) && is_array($v[0])) {
                    echo ' firstKeys='.implode(',', array_keys($v[0]));
                } elseif ($v !== [] && ! array_is_list($v)) {
                    echo ' keys='.implode(',', array_keys($v));
                }
            }
            echo PHP_EOL;
        }
        if (is_array($v)) {
            findPath($v, $needle, $p);
        }
    }
}

foreach (['trips', 'boats', 'tripBoats', 'component', 'props', 'page'] as $needle) {
    findPath($j, $needle);
}

// dump page structure shallow
if (isset($j['page'])) {
    echo 'page keys: '.implode(', ', array_keys($j['page'])).PHP_EOL;
}
if (isset($j['response'])) {
    echo 'response keys: '.implode(', ', array_keys($j['response'])).PHP_EOL;
}

// maybe deferred/props encoded as string
$raw = file_get_contents($file);
if (preg_match('/"component":"([^"]+)"/', $raw, $m)) {
    echo 'component='.$m[1].PHP_EOL;
}
if (preg_match('/"trips":\[/', $raw)) {
    echo "raw has trips array\n";
}
if (preg_match('/CAIX/', $raw)) {
    echo "raw has CAIX\n";
    // extract nearby context
    $pos = strpos($raw, 'CAIX');
    echo substr($raw, max(0, $pos - 200), 500).PHP_EOL;
}
