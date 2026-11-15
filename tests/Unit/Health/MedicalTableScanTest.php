<?php

/** @return array<string, string> path relative to app/ => source */
function mdTblSources(string $sub = ''): array
{
    $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/app/';
    if (! is_dir($root.$sub)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$sub, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $out[str_replace($root, '', str_replace('\\', '/', $f->getPathname()))] = (string) file_get_contents($f->getPathname());
        }
    }
    ksort($out);

    return $out;
}

/**
 * @param  array<string, string>  $sources
 * @param  callable(string): bool  $allowed  receives the path relative to app/
 * @return list<string> path:line of every hit outside the allowed paths
 */
function mdTblHits(array $sources, string $regex, callable $allowed): array
{
    $bad = [];
    foreach ($sources as $rel => $src) {
        if ($allowed($rel)) {
            continue;
        }
        foreach (preg_split('/\R/', $src) ?: [] as $i => $line) {
            if (preg_match($regex, $line)) {
                $bad[] = $rel.':'.($i + 1);
            }
        }
    }

    return $bad;
}

/** A table name carries the underscore; `medical:prune-orphans` (a command signature) and `App\Medical` do not match. */
const MD_TBL_REGEX = '/medical_[a-z]/';

$mdTblAllowed = fn (string $rel) => str_starts_with($rel, 'Medical/Queries/')
    || $rel === 'Medical/Visibility.php'
    // The models name their own table; AuditEvent holds event slugs such as medical_subject_created, not tables.
    || (bool) preg_match('#^Models/Medical[A-Za-z]*\.php$#', $rel)
    || $rel === 'Audit/AuditEvent.php'
    // StagingAudit (single-file allowlist entry, added by 07-19) names the medical tables in its synthetic-data-only rules.
    || $rel === 'Console/Commands/StagingAudit.php';

it('keeps medical table names inside the query layer, the scope and the models', function () use ($mdTblAllowed) {
    expect(mdTblHits(mdTblSources(), MD_TBL_REGEX, $mdTblAllowed))->toBe([]);
});

it('reports a medical table name planted outside the allowed paths (scanner self-test)', function () use ($mdTblAllowed) {
    $planted = [
        'Http/Controllers/HomeController.php' => "<?php\nDB::table('medical_visits')->count();\n",
        'Medical/PersonPage.php' => "<?php\n\$sql = 'select * from medical_subjects';\n",
        'Medical/Queries/VisitQuery.php' => "<?php\nDB::table('medical_visits');\n",
        'Medical/Visibility.php' => "<?php\n'medical_subject_members';\n",
        'Console/Commands/StagingAudit.php' => "<?php\n// medical_documents\n",
        'Console/Commands/PruneMedicalOrphans.php' => "<?php\nprotected \$signature = 'medical:prune-orphans';\n// medical_documents\n",
    ];

    expect(mdTblHits($planted, MD_TBL_REGEX, $mdTblAllowed))->toBe([
        'Http/Controllers/HomeController.php:2',
        'Medical/PersonPage.php:2',
        'Console/Commands/PruneMedicalOrphans.php:3',
    ]);
});

it('keeps static medical model reads and bulk writes inside the query layer', function () {
    $bad = mdTblHits(
        mdTblSources(),
        '/\bMedical[A-Z][A-Za-z]*::(query|where[A-Za-z]*|find[A-Za-z]*|all|first[A-Za-z]*|count|sum|pluck|exists|latest|oldest|orderBy|with|insert[A-Za-z]*|upsert|update|destroy|updateOrCreate|firstOrCreate)\(/',
        fn (string $rel) => str_starts_with($rel, 'Medical/Queries/'),
    );
    expect($bad)->toBe([]);
});

it('keeps raw DB access in app/Medical inside the query layer and the scope', function () {
    $bad = mdTblHits(
        mdTblSources('Medical'),
        '/\bDB::(table|select|statement|raw|insert|update|delete|unprepared)\b/',
        fn (string $rel) => str_starts_with($rel, 'Medical/Queries/') || $rel === 'Medical/Visibility.php',
    );
    expect($bad)->toBe([]);
});

it('never validates medical ids with database-backed rules in form requests', function () {
    // Rule::exists is banned in the health request classes: an existence check is an oracle for hidden ids. Ids resolve
    // through App\Medical\Queries instead. Elsewhere (for example users) it stays allowed.
    $medicalRequests = fn (string $rel) => ! (str_starts_with($rel, 'Http/Requests/Health/') || str_starts_with($rel, 'Http/Requests/Settings/HealthPeople'));
    $prefixed = mdTblSources('Http/Requests');
    $bad = array_merge(
        mdTblHits($prefixed, '/Rule::exists\(/', $medicalRequests),
        mdTblHits($prefixed, '/exists:medical_|Rule::exists\([^)]*medical_/', fn () => false),
    );
    expect($bad)->toBe([])
        ->and(mdTblHits(['Http/Requests/Health/X.php' => "<?php\nRule::exists('users', 'id');\n"], '/Rule::exists\(/', $medicalRequests))->toBe(['Http/Requests/Health/X.php:2'])
        ->and(mdTblHits(['Http/Requests/Health/X.php' => "<?php\n'exists:medical_subjects,id';\n"], '/exists:medical_|Rule::exists\([^)]*medical_/', fn () => false))->toBe(['Http/Requests/Health/X.php:2']);
});

it('allowlists exactly one console command for medical table names and nothing else outside the layer', function () {
    $console = mdTblSources('Console');
    $files = fn (array $hits) => array_values(array_unique(array_map(fn ($h) => explode(':', $h)[0], $hits)));
    $hits = $files(mdTblHits($console, MD_TBL_REGEX, fn () => false));

    // Empty until 07-19 adds the StagingAudit rules, then exactly that one file. The signature `medical:prune-orphans`
    // has no underscore and never matches.
    expect($hits === [] || $hits === ['Console/Commands/StagingAudit.php'])->toBeTrue('console files naming medical tables: '.implode(', ', $hits));

    $allowStaging = fn (string $rel) => $rel === 'Console/Commands/StagingAudit.php';
    $planted = ['Console/Commands/StagingAudit.php' => "<?php\n// medical_documents\n"];
    expect(mdTblHits($planted, MD_TBL_REGEX, $allowStaging))->toBe([])
        ->and(mdTblHits($planted + ['Console/Commands/ZzScanProbe.php' => "<?php\n// medical_visits\n"], MD_TBL_REGEX, $allowStaging))->toBe(['Console/Commands/ZzScanProbe.php:2']);
});
