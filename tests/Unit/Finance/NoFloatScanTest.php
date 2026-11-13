<?php

/** @return list<string> */
function fhScanFiles(array $dirs, array $exts, ?string $only = null, ?string $except = null): array
{
    $files = [];
    foreach ($dirs as $dir) {
        $path = dirname(__DIR__, 3).'/'.$dir;
        if (! is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            if (! in_array($f->getExtension(), $exts, true)) {
                continue;
            }
            if ($only !== null && ! str_contains($p, $only)) {
                continue;
            }
            if ($except !== null && str_contains($p, $except)) {
                continue;
            }
            $files[] = $p;
        }
    }

    return $files;
}

function fhScanOffences(array $files, array $tokens): array
{
    $bad = [];
    foreach ($files as $file) {
        $src = file_get_contents($file);
        foreach ($tokens as $t) {
            if (str_contains($src, $t)) {
                $bad[] = basename($file).' contains '.$t;
            }
        }
    }

    return $bad;
}

it('forbids float money code in app/Finance', function () {
    $bad = fhScanOffences(
        fhScanFiles(['app/Finance'], ['php']),
        ['floatval(', '(float)', 'round(', 'number_format(', 'NumberFormatter', 'bcadd(', 'bcsub(', 'bcmul(', 'bcdiv('],
    );
    expect($bad)->toBe([]);
});

it('forbids float money code in finance JS and Vue outside charts', function () {
    $bad = fhScanOffences(
        fhScanFiles(['resources/js/Finance', 'resources/js/Pages/Finance'], ['js', 'vue'], null, '/Finance/charts/'),
        ['parseFloat(', 'Number(', 'toFixed(', 'Math.round('],
    );
    expect($bad)->toBe([]);
});

it('allows pixel math in charts but not parseFloat or toFixed', function () {
    $bad = fhScanOffences(
        fhScanFiles(['resources/js/Finance/charts'], ['js', 'vue']),
        ['parseFloat(', 'toFixed('],
    );
    expect($bad)->toBe([]);
});
