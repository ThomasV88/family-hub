<?php

/** @return list<string> */
function fhTblFiles(string $sub): array
{
    $root = dirname(__DIR__, 3).'/app/'.$sub;
    if (! is_dir($root)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);

    return $out;
}

/**
 * @param  list<string>  $files
 * @param  callable(string): bool  $allowed  receives the path relative to app/
 * @return list<string>
 */
function fhTblHits(array $files, string $regex, callable $allowed): array
{
    $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/app/';
    $bad = [];
    foreach ($files as $file) {
        $rel = str_replace($root, '', $file);
        if ($allowed($rel)) {
            continue;
        }
        foreach (file($file) ?: [] as $i => $line) {
            if (preg_match($regex, $line)) {
                $bad[] = $rel.':'.($i + 1);
            }
        }
    }

    return $bad;
}

$fhTblInQueries = fn (string $rel) => str_starts_with($rel, 'Finance/Queries/');

it('keeps finance table names inside the query layer, the scope and the models', function () use ($fhTblInQueries) {
    $bad = fhTblHits(
        fhTblFiles(''),
        '/finance_(accounts|account_members|categories|transactions|recurring|recurring_matches|match_rejections|budgets|import_profiles|import_batches|import_rows)\b/',
        fn (string $rel) => $fhTblInQueries($rel) || $rel === 'Finance/Visibility.php' || (bool) preg_match('#^Models/Finance[A-Za-z]*\.php$#', $rel),
    );
    expect($bad)->toBe([]);
});

it('keeps static finance model reads and bulk writes inside the query layer', function () use ($fhTblInQueries) {
    $bad = fhTblHits(
        fhTblFiles(''),
        '/\bFinance[A-Z][A-Za-z]*::(query|where[A-Za-z]*|find[A-Za-z]*|all|first[A-Za-z]*|count|sum|pluck|exists|latest|oldest|orderBy|with|insert[A-Za-z]*|upsert|update|destroy|updateOrCreate|firstOrCreate)\(/',
        $fhTblInQueries,
    );
    expect($bad)->toBe([]);
});

it('keeps raw DB access in app/Finance inside the query layer and the scope', function () use ($fhTblInQueries) {
    $bad = fhTblHits(
        fhTblFiles('Finance'),
        '/\bDB::(table|select|statement|raw|insert|update|delete|unprepared)\b/',
        fn (string $rel) => $fhTblInQueries($rel) || $rel === 'Finance/Visibility.php',
    );
    expect($bad)->toBe([]);
});

it('never validates finance ids with database-backed rules in form requests', function () {
    // Rule::exists is banned in finance request classes (an exists rule leaks hidden ids through its error);
    // elsewhere (for example users) it stays allowed, but never against a finance table.
    $financeRequests = fn (string $rel) => ! (str_starts_with($rel, 'Http/Requests/Finance/') || str_starts_with($rel, 'Http/Requests/Settings/FinanceAccount'));
    $bad = array_merge(
        fhTblHits(fhTblFiles('Http/Requests'), '/Rule::exists\(/', $financeRequests),
        fhTblHits(fhTblFiles('Http/Requests'), '/exists:finance_|Rule::exists\([^)]*finance_/', fn () => false),
    );
    expect($bad)->toBe([]);
});
