<?php

/*
 * Slovak name days from jozefgrencik/slovak-name-days (MIT), data/names.json at a pinned commit.
 *
 *   php build-sk.php <source names.json> <out> <version>
 */

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

if (! isset($argv[1], $argv[2], $argv[3])) {
    fail('usage: build-sk.php <names.json> <out> <version>');
}
$source = json_decode((string) @file_get_contents($argv[1]), true);
if (! is_array($source)) {
    fail('cannot read source');
}
$days = [];
for ($mo = 1; $mo <= 12; $mo++) {
    for ($d = 1; $d <= 31; $d++) {
        if (checkdate($mo, $d, 2024)) {
            $days[sprintf('%02d-%02d', $mo, $d)] = [];
        }
    }
}
foreach ($source as $entry) {
    $key = sprintf('%02d-%02d', $entry['month'] ?? 0, $entry['day'] ?? 0);
    if (! isset($days[$key]) || ! is_array($entry['names'] ?? null)) {
        fail("parse error $key");
    }
    $days[$key] = array_values(array_unique(array_map('trim', $entry['names'])));
}
$doc = [
    'calendar' => 'sk',
    'version' => $argv[3],
    'source' => 'https://github.com/jozefgrencik/slovak-name-days',
    'license' => 'MIT',
    'days' => $days,
];
file_put_contents($argv[2], json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
