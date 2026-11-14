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

const AI_ARCH_PROCESS_ALLOWED = ['Medical/Ai/CodexRunner.php', 'Medical/Ai/CopilotRunner.php', 'Medical/Documents/PdfRasterizer.php', 'Medical/Ai/Preflight.php'];

// Where Copilot's environment variable names and fixed login location may be spelled out. WorkDir names '/copilot' only
// to refuse a work dir that overlaps the login volume (added in 09-08).
const AI_ARCH_COPILOT_ALLOWED = ['Medical/Ai/CopilotRunner.php', 'Medical/Ai/Preflight.php', 'Medical/Ai/Work/WorkDir.php'];

const AI_ARCH_COPILOT_LITERALS = [
    'COPILOT_' => '/COPILOT_/',
    "'/copilot'" => "#'/copilot'#",
];

// Flags that would widen what an agentic CLI may do, or resume/share a session. Never allowed anywhere under app/.
const AI_ARCH_BANNED_FLAGS = [
    '--allow-all' => '/--allow-all/',
    '--yolo' => '/--yolo/',
    '--allow-tool' => '/--allow-tool/',
    '--trust-folder' => '/--trust-folder/',
    '--share' => '/--share/',
    '--resume' => '/--resume/',
    '--continue' => '/--continue/',
    '--autopilot' => '/--autopilot/',
    '--assisted-approval' => '/--assisted-approval/',
];

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

it('spawns processes only in the runners, PdfRasterizer and Preflight', function () {
    expect(aiArchViolations(aiArchSources(), AI_ARCH_PROCESS_PATTERNS, AI_ARCH_PROCESS_ALLOWED))->toBe([]);
});

it('keeps log, report and dump calls out of the AI layer', function () {
    expect(aiArchViolations(aiArchSources('Medical/Ai'), AI_ARCH_LOG_PATTERNS))->toBe([]);
});

/**
 * @param  array<string, string>  $sources
 * @param  list<string>  $needles
 * @return list<string>
 */
function aiArchControllerViolations(array $sources, array $needles): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        foreach ($needles as $needle) {
            if (str_contains($src, $needle)) {
                $bad[] = $path.': '.$needle;
            }
        }
    }

    return $bad;
}

it('never lets controllers use CodexRunner or CopilotRunner', function () {
    expect(aiArchControllerViolations(aiArchSources('Http/Controllers'), ['CodexRunner', 'CopilotRunner']))->toBe([]);
});

it('confines Copilot environment names and its login location', function () {
    expect(aiArchViolations(aiArchSources(), AI_ARCH_COPILOT_LITERALS, AI_ARCH_COPILOT_ALLOWED))->toBe([]);
});

it('never names a flag that widens an agent CLI or resumes a session', function () {
    expect(aiArchViolations(aiArchSources(), AI_ARCH_BANNED_FLAGS))->toBe([]);
});

it('catches Copilot mutations in memory', function () {
    $controller = ['Http/Controllers/X.php' => aiArchStrip('<?php use App\\Medical\\Ai\\CopilotRunner; (new CopilotRunner)->exec($r);')];
    expect(aiArchControllerViolations($controller, ['CodexRunner', 'CopilotRunner']))->toBe(['Http/Controllers/X.php: CopilotRunner']);

    $env = ['Medical/Ai/Other.php' => aiArchStrip('<?php $e = ["COPILOT_HOME=/x", \'/copilot\'];')];
    expect(aiArchViolations($env, AI_ARCH_COPILOT_LITERALS, AI_ARCH_COPILOT_ALLOWED))
        ->toBe(['Medical/Ai/Other.php: COPILOT_', "Medical/Ai/Other.php: '/copilot'"])
        ->and(aiArchViolations(['Medical/Ai/CopilotRunner.php' => $env['Medical/Ai/Other.php']], AI_ARCH_COPILOT_LITERALS, AI_ARCH_COPILOT_ALLOWED))->toBe([]);

    $flags = ['Medical/Ai/Y.php' => aiArchStrip('<?php $a = ["--allow-all-tools", "--resume"];')];
    expect(aiArchViolations($flags, AI_ARCH_BANNED_FLAGS))->toBe(['Medical/Ai/Y.php: --allow-all', 'Medical/Ai/Y.php: --resume']);

    $process = ['Medical/Ai/Agents/Z.php' => aiArchStrip('<?php $p = new Process(["copilot"]);')];
    expect(aiArchViolations($process, AI_ARCH_PROCESS_PATTERNS, AI_ARCH_PROCESS_ALLOWED))->toBe(['Medical/Ai/Agents/Z.php: new Process(']);
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
