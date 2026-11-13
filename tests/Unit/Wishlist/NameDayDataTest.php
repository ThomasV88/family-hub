<?php

use Symfony\Component\Process\Process;

function nameDayRoot(): string
{
    return dirname(__DIR__, 3).'/database/data/namedays';
}

function nameDayData(string $calendar): array
{
    return json_decode(file_get_contents(nameDayRoot()."/{$calendar}.json"), true, 512, JSON_THROW_ON_ERROR);
}

dataset('calendars', [
    'sk' => ['sk', 'MIT', 355, 0],
    'pl' => ['pl', 'CC BY-SA 4.0', 360, 1000],
]);

it('is a complete 366 day calendar with provenance', function (string $calendar, string $license, int $minDays, int $minNames) {
    $data = nameDayData($calendar);

    expect($data['calendar'])->toBe($calendar)
        ->and($data['license'])->toBe($license)
        ->and($data['version'])->not->toBeEmpty()
        ->and($data['source'])->not->toBeEmpty()
        ->and($data['days'])->toHaveCount(366)
        ->and($data['days'])->toHaveKey('02-29');

    foreach (array_keys($data['days']) as $key) {
        expect($key)->toMatch('/^\d{2}-\d{2}$/');
        [$m, $d] = array_map('intval', explode('-', $key));
        expect(checkdate($m, $d, 2024))->toBeTrue();
    }

    $nonEmpty = count(array_filter($data['days']));
    $names = array_sum(array_map('count', $data['days']));
    expect($nonEmpty)->toBeGreaterThanOrEqual($minDays)
        ->and($names)->toBeGreaterThanOrEqual($minNames);
})->with('calendars');

it('contains only clean names', function (string $calendar) {
    foreach (nameDayData($calendar)['days'] as $day => $names) {
        expect(array_values(array_unique($names)))->toBe($names);
        foreach ($names as $name) {
            expect($name)->toMatch('/^\p{Lu}[\p{L}\p{M}\'\- ]{0,40}$/u');
        }
    }
})->with(['sk', 'pl']);

it('rebuilds byte-identical files from the stored sources', function () {
    $base = nameDayRoot();
    $tmp = tempnam(sys_get_temp_dir(), 'nd');

    $pl = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/namedays/build-pl.php', '--build', "$base/source/pl", $tmp]);
    $pl->mustRun();
    expect(file_get_contents($tmp))->toBe(file_get_contents("$base/pl.json"));

    $version = nameDayData('sk')['version'];
    $sk = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/namedays/build-sk.php', "$base/source/sk/names.json", $tmp, $version]);
    $sk->mustRun();
    expect(file_get_contents($tmp))->toBe(file_get_contents("$base/sk.json"));

    unlink($tmp);
});

it('ships the CC BY-SA licence notice with the Polish data', function () {
    $notice = file_get_contents(base_path('database/data/namedays/LICENSE-pl.txt'));

    expect($notice)->toContain('https://creativecommons.org/licenses/by-sa/4.0/legalcode')
        ->toContain('https://pl.wikipedia.org/wiki/Imieniny')->toContain('Modifications')->toContain('78166931');
});
