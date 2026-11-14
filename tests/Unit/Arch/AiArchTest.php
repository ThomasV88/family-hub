<?php

/** Comment-stripped PHP sources under app/, keyed by path relative to app/. */
function aiArchSources(string $sub = ''): array
{
    $root = dirname(__DIR__, 3).'/app/';
    $dir = $root.$sub;
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $out[str_replace($root, '', str_replace('\\', '/', $f->getPathname()))] = aiArchStrip((string) file_get_contents($f->getPathname()));
        }
    }
    ksort($out);

    return $out;
}

function aiArchStrip(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }

    return $out;
}

const AI_ARCH_PROCESS_PATTERNS = [
    'Symfony\\\\Component\\\\Process' => '/Symfony\\\\Component\\\\Process/',
    'new Process(' => '/new\s+Process\s*\(/',
    'proc_open(' => '/(?<![\w>:$])(?<!function )proc_open\s*\(/',
    'shell_exec(' => '/(?<![\w>:$])(?<!function )shell_exec\s*\(/',
    'exec(' => '/(?<![\w>:$])(?<!function )exec\s*\(/',
    'passthru(' => '/(?<![\w>:$])(?<!function )passthru\s*\(/',
    'system(' => '/(?<![\w>:$])(?<!function )system\s*\(/',
    'popen(' => '/(?<![\w>:$])(?<!function )popen\s*\(/',
    "'pdftoppm'" => "/'pdftoppm'/",
    "'pdfinfo'" => "/'pdfinfo'/",
];

const AI_ARCH_PROCESS_ALLOWED = ['Medical/Ai/CodexRunner.php', 'Medical/Documents/PdfRasterizer.php'];

const AI_ARCH_LOG_PATTERNS = [
    'Log::' => '/\bLog::/',
    'logger(' => '/(?<![\w>:$])(?<!function )logger\s*\(/',
    'report(' => '/(?<![\w>:$])(?<!function )report\s*\(/',
    'dd(' => '/(?<![\w>:$])(?<!function )dd\s*\(/',
    'dump(' => '/(?<![\w>:$])(?<!function )dump\s*\(/',
    'error_log(' => '/(?<![\w>:$])(?<!function )error_log\s*\(/',
];

/**
 * @param  array<string, string>  $sources
 * @param  array<string, string>  $patterns
 * @param  list<string>  $allowed
 * @return list<string> "path: token"
 */
function aiArchViolations(array $sources, array $patterns, array $allowed = []): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        if (in_array($path, $allowed, true)) {
            continue;
        }
        foreach ($patterns as $name => $re) {
            if (preg_match($re, $src) === 1) {
                $bad[] = $path.': '.$name;
            }
        }
    }

    return $bad;
}

it('spawns processes only in CodexRunner and PdfRasterizer', function () {
    expect(aiArchViolations(aiArchSources(), AI_ARCH_PROCESS_PATTERNS, AI_ARCH_PROCESS_ALLOWED))->toBe([]);
});

it('keeps log, report and dump calls out of the AI layer', function () {
    expect(aiArchViolations(aiArchSources('Medical/Ai'), AI_ARCH_LOG_PATTERNS))->toBe([]);
});

it('never lets controllers use CodexRunner', function () {
    $bad = [];
    foreach (aiArchSources('Http/Controllers') as $path => $src) {
        if (str_contains($src, 'CodexRunner')) {
            $bad[] = $path;
        }
    }
    expect($bad)->toBe([]);
});

it('detects synthetic violations and ignores comments and method calls', function () {
    $dirty = ['X.php' => aiArchStrip('<?php new Process([]); Log::info("x"); shell_exec("ls"); exec("ls"); $p = "pdftoppm"; echo \'pdftoppm\';')];
    expect(aiArchViolations($dirty, AI_ARCH_PROCESS_PATTERNS))->toContain('X.php: new Process(', 'X.php: shell_exec(', 'X.php: exec(', "X.php: 'pdftoppm'")
        ->and(aiArchViolations($dirty, AI_ARCH_LOG_PATTERNS))->toContain('X.php: Log::');

    $clean = ['Y.php' => aiArchStrip("<?php\n// exec( Log::info(\n/* proc_open( */ \$a->exec(\$b); Foo::exec(\$c); function exec() {}")];
    expect(aiArchViolations($clean, AI_ARCH_PROCESS_PATTERNS))->toBe([])
        ->and(aiArchViolations($clean, AI_ARCH_LOG_PATTERNS))->toBe([]);

    expect(aiArchViolations($dirty, AI_ARCH_PROCESS_PATTERNS, ['X.php']))->toBe([]);
});
