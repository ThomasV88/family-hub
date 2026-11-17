<?php

/**
 * Pins where the recognised document text may be named. The store is a Queries class (the repo's table-name and raw
 * database scans require it), so the list below holds paths relative to app/. Later plans extend it: 13-04 adds
 * DocumentSearchQuery and DocumentTextQuery, 13-06 the job side.
 */
const DOC_TEXT_ARCH_TABLE_ALLOWED = [
    'Medical/Queries/DocumentTextStore.php',
    'Console/Commands/StagingAudit.php',
];

const DOC_TEXT_ARCH_TABLE = 'medical_document_texts';

/** @return array<string, string> path relative to app/ => comment-stripped source */
function docTextArchSources(string $sub = ''): array
{
    $root = dirname(__DIR__, 3).'/app/';
    if (! is_dir($root.$sub)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$sub, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $out[str_replace($root, '', str_replace('\\', '/', $f->getPathname()))] = docTextArchStrip((string) file_get_contents($f->getPathname()));
        }
    }
    ksort($out);

    return $out;
}

function docTextArchStrip(string $src): string
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

/**
 * @param  array<string, string>  $sources
 * @param  list<string>  $allowed
 * @return list<string>
 */
function docTextArchTableViolations(array $sources, array $allowed): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        if (! in_array($path, $allowed, true) && str_contains($src, DOC_TEXT_ARCH_TABLE)) {
            $bad[] = $path;
        }
    }

    return $bad;
}

/**
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function docTextArchModelViolations(array $sources): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        if (str_contains($src, 'document_texts') || str_contains($src, 'DocumentText')) {
            $bad[] = $path;
        }
    }

    return $bad;
}

/**
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function docTextArchLogViolations(array $sources): array
{
    $patterns = [
        'Log::' => '/\bLog::/',
        'logger(' => '/(?<![\w>:$])(?<!function )logger\s*\(/',
        'report(' => '/(?<![\w>:$])(?<!function )report\s*\(/',
        'dump(' => '/(?<![\w>:$])(?<!function )dump\s*\(/',
        'dd(' => '/(?<![\w>:$])(?<!function )dd\s*\(/',
        'error_log(' => '/(?<![\w>:$])(?<!function )error_log\s*\(/',
    ];
    $bad = [];
    foreach ($sources as $path => $src) {
        foreach ($patterns as $name => $re) {
            if (preg_match($re, $src) === 1) {
                $bad[] = $path.': '.$name;
            }
        }
    }

    return $bad;
}

/** The export code and the emergency card, by path relative to app/. */
function docTextArchExportSources(): array
{
    $sources = docTextArchSources('Medical/Export');
    foreach (['Medical/Queries/ExportQuery.php', 'Medical/EmergencyCard.php', 'Http/Controllers/Health/ExportController.php', 'Http/Controllers/Health/EmergencyCardController.php'] as $rel) {
        $sources += array_intersect_key(docTextArchSources(dirname($rel)), [$rel => true]);
    }

    return $sources;
}

it('lets only the pinned classes name the text table', function () {
    expect(docTextArchTableViolations(docTextArchSources(), DOC_TEXT_ARCH_TABLE_ALLOWED))->toBe([])
        ->and(docTextArchTableViolations(['Medical/Queries/DocumentTextStore.php' => DOC_TEXT_ARCH_TABLE], DOC_TEXT_ARCH_TABLE_ALLOWED))->toBe([])
        ->and(docTextArchTableViolations(['Medical/PersonPage.php' => "DB::table('".DOC_TEXT_ARCH_TABLE."')"], DOC_TEXT_ARCH_TABLE_ALLOWED))->toBe(['Medical/PersonPage.php']);
});

it('pins the store itself, so the allowlist cannot go stale', function () {
    $sources = docTextArchSources();

    expect($sources)->toHaveKey('Medical/Queries/DocumentTextStore.php')
        ->and($sources['Medical/Queries/DocumentTextStore.php'])->toContain(DOC_TEXT_ARCH_TABLE);
});

it('keeps the text off the MedicalDocument model', function () {
    $model = ['Models/MedicalDocument.php' => docTextArchSources('Models')['Models/MedicalDocument.php'] ?? ''];

    expect($model['Models/MedicalDocument.php'])->not->toBe('')
        ->and(docTextArchModelViolations($model))->toBe([])
        ->and(docTextArchModelViolations(['Models/MedicalDocument.php' => 'public function texts() { return $this->hasOne(DocumentText::class); }']))->toBe(['Models/MedicalDocument.php'])
        ->and(docTextArchModelViolations(['Models/MedicalDocument.php' => "->with('document_texts')"]))->toBe(['Models/MedicalDocument.php']);
});

it('keeps log, report and dump calls out of the text namespace', function () {
    $sources = docTextArchSources('Medical/Documents/Text');
    $store = docTextArchSources('Medical/Queries')['Medical/Queries/DocumentTextStore.php'] ?? '';

    expect($sources)->not->toBe([])
        ->and(docTextArchLogViolations($sources + ['Medical/Queries/DocumentTextStore.php' => $store]))->toBe([])
        ->and(docTextArchLogViolations(['Medical/Documents/Text/X.php' => 'Log::info($t);']))->toBe(['Medical/Documents/Text/X.php: Log::'])
        ->and(docTextArchLogViolations(['Medical/Documents/Text/X.php' => docTextArchStrip("<?php\n// Log::info(1)\n\$this->report(\$e); function dump() {}")]))->toBe([]);
});

it('keeps the export code and the emergency card away from the text', function () {
    $sources = docTextArchExportSources();

    expect(array_keys($sources))->toContain('Medical/Export/Exporter.php')->toContain('Medical/EmergencyCard.php')->toContain('Medical/Queries/ExportQuery.php')
        ->and(docTextArchTableViolations($sources, []))->toBe([])
        ->and(docTextArchModelViolations($sources))->toBe([])
        ->and(docTextArchModelViolations(['Medical/Export/Exporter.php' => 'use App\Medical\Documents\Text\DocumentText;']))->toBe(['Medical/Export/Exporter.php']);
});
