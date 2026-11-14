<?php

/**
 * Static half of the mutation scope rule: a medical write must never change a row of a subject the editor cannot see.
 * Rows are changed only inside App\Medical\Queries, and only by methods carrying Visibility::. The second half is the
 * file-store scan: the private disk is reachable only through MedicalDocumentStore, whose methods only a few callers use.
 */
const MD_MUT_TOKENS = ['->update(', '->delete(', '->forceDelete(', '->increment(', '->decrement(', '->updateQuietly(', '->deleteQuietly(', 'upsert(', '->updateOrInsert(', '->updateOrCreate(', 'DB::update(', 'DB::delete('];

const MD_MUT_OWNED = [
    'medical_subjects', 'medical_visits', 'medical_medications', 'medical_allergies', 'medical_vaccinations',
    'medical_contacts', 'medical_documents', 'medical_subject_members',
    'MedicalSubject', 'MedicalVisit', 'MedicalMedication', 'MedicalAllergy', 'MedicalVaccination', 'MedicalContact', 'MedicalDocument',
];

/** Whole classes exempt from the Visibility:: rule, by exact class name, each with its documented reason. */
const MD_MUT_ALLOWLIST = [
    'AdminSubjectQuery' => 'admin settings by design: the admin manages membership and links with no medical content (07-07)',
    'SystemQuery' => 'no viewer: system jobs touch ids and booleans only, never content (07-09, 07-12, 07-15)',
];

/**
 * Splits PHP source into methods.
 *
 * @return list<array{class: string, method: string, body: string, doc: string}>
 */
function mdMutMethods(string $src): array
{
    $tokens = PhpToken::tokenize($src);
    $n = count($tokens);
    $class = '';
    $out = [];
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_WHITESPACE];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->is(T_CLASS)) {
            $prev = $i - 1;
            while ($prev >= 0 && $tokens[$prev]->is(T_WHITESPACE)) {
                $prev--;
            }
            if ($prev >= 0 && $tokens[$prev]->is([T_DOUBLE_COLON, T_NEW])) {
                continue;
            }
            $j = $i + 1;
            while ($tokens[$j]->is(T_WHITESPACE)) {
                $j++;
            }
            $class = $tokens[$j]->text;
        }
        if (! $t->is(T_FUNCTION)) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->text === '&')) {
            $j++;
        }
        if (! $tokens[$j]->is(T_STRING)) {
            continue; // closure or arrow function
        }
        $name = $tokens[$j]->text;
        $doc = '';
        for ($k = $i - 1; $k >= 0 && $tokens[$k]->is($modifiers); $k--) {
        }
        if ($k >= 0 && $tokens[$k]->is(T_DOC_COMMENT)) {
            $doc = $tokens[$k]->text;
        }
        while ($j < $n && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
            $j++;
        }
        if ($j >= $n || $tokens[$j]->text === ';') {
            continue;
        }
        $depth = 0;
        $body = '';
        for (; $j < $n; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '{' || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
            $body .= $text;
            if ($depth === 0) {
                break;
            }
        }
        $out[] = ['class' => $class, 'method' => $name, 'body' => $body, 'doc' => $doc];
    }

    return $out;
}

/** @param  list<string>  $tokens */
function mdMutHasToken(string $body, array $tokens): bool
{
    foreach ($tokens as $t) {
        if (str_contains($body, $t)) {
            return true;
        }
    }

    return false;
}

function mdMutIsMutation(string $body): bool
{
    return mdMutHasToken($body, MD_MUT_TOKENS)
        || preg_match('/\bupdate\s+"?medical_|\bdelete\s+from\s+"?medical_|\bdo\s+update\b/i', $body) === 1;
}

/**
 * Queries-layer rule: Class::method of every mutation of a medical table that lacks Visibility:: (allowlisted classes
 * skipped by exact class name only).
 *
 * @return list<string>
 */
function mdMutUnscoped(string $src): array
{
    $bad = [];
    foreach (mdMutMethods($src) as $m) {
        if (array_key_exists($m['class'], MD_MUT_ALLOWLIST)) {
            continue;
        }
        if (mdMutIsMutation($m['body']) && mdMutHasToken($m['body'], MD_MUT_OWNED) && ! str_contains($m['body'], 'Visibility::')) {
            $bad[] = $m['class'].'::'.$m['method'];
        }
    }

    return $bad;
}

/**
 * Outside-the-layer rule over the whole app: a method that both mutates and names a medical table or model.
 *
 * @param  array<string, string>  $sources  path relative to app/ => source
 * @return list<string>
 */
function mdMutOutside(array $sources): array
{
    $bad = [];
    foreach ($sources as $rel => $src) {
        if (str_starts_with($rel, 'Medical/Queries/')) {
            continue;
        }
        foreach (mdMutMethods($src) as $m) {
            if (mdMutIsMutation($m['body']) && mdMutHasToken($m['body'], MD_MUT_OWNED)) {
                $bad[] = $rel.' '.$m['class'].'::'.$m['method'];
            }
        }
    }

    return $bad;
}

/** @return array<string, string> path relative to app/ => source */
function mdMutSources(string $sub = ''): array
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

// ---- file-store scan --------------------------------------------------------------------------------------------

const MD_STORE_FILE = 'Medical/Documents/MedicalDocumentStore.php';

const MD_STORE_USERS = [
    'Medical/Documents/MedicalDocumentStore.php',
    'Http/Controllers/Health/DocumentController.php',
    'Medical/Queries/SubjectQuery.php',
    'Console/Commands/PruneMedicalOrphans.php',
];

/** Store method => the only files that may call it (path relative to app/). */
const MD_STORE_METHODS = [
    'put' => ['Http/Controllers/Health/DocumentController.php'],
    'delete' => ['Http/Controllers/Health/DocumentController.php', 'Console/Commands/PruneMedicalOrphans.php'],
    'deleteSubjectDirectory' => ['Medical/Queries/SubjectQuery.php', 'Console/Commands/PruneMedicalOrphans.php'],
    'absolutePath' => ['Http/Controllers/Health/DocumentController.php'],
    'files' => ['Console/Commands/PruneMedicalOrphans.php'],
    'subjectDirectories' => ['Console/Commands/PruneMedicalOrphans.php'],
];

/**
 * @param  array<string, string>  $sources  path relative to app/ => source
 * @return list<string> one entry per violation
 */
function mdStoreViolations(array $sources): array
{
    $bad = [];
    foreach ($sources as $rel => $src) {
        if (preg_match('/\bdisk\(\s*[\'"]medical[\'"]\s*\)/', $src) === 1 && $rel !== MD_STORE_FILE) {
            $bad[] = "$rel: medical disk used outside MedicalDocumentStore";
        }
        if ($rel === MD_STORE_FILE || ! str_contains($src, 'MedicalDocumentStore')) {
            continue;
        }
        if (! in_array($rel, MD_STORE_USERS, true)) {
            $bad[] = "$rel: references MedicalDocumentStore";

            continue;
        }
        // Calls on the store: through a typed variable or property, a container resolve, or a static call.
        preg_match_all('/MedicalDocumentStore\s+\$(\w+)/', $src, $vars);
        $methods = [];
        foreach (array_unique($vars[1]) as $var) {
            preg_match_all('/\$(?:this\s*->\s*)?'.preg_quote($var, '/').'\s*->\s*(\w+)\s*\(/', $src, $m);
            $methods = array_merge($methods, $m[1]);
        }
        preg_match_all('/MedicalDocumentStore::class\s*\)\s*->\s*(\w+)\s*\(/', $src, $resolved);
        preg_match_all('/MedicalDocumentStore::(?!class\b)(\w+)\s*\(/', $src, $static);
        foreach (array_unique(array_merge($methods, $resolved[1], $static[1])) as $method) {
            if (isset(MD_STORE_METHODS[$method]) && ! in_array($rel, MD_STORE_METHODS[$method], true)) {
                $bad[] = "$rel: calls store $method()";
            }
        }
    }

    return $bad;
}

// ---- tests ------------------------------------------------------------------------------------------------------

it('forbids row-changing calls on medical tables outside the query layer', function () {
    expect(mdMutOutside(mdMutSources()))->toBe([]);
});

it('requires Visibility:: in every query-layer method that mutates medical rows', function () {
    $bad = [];
    foreach (mdMutSources('Medical/Queries') as $src) {
        $bad = array_merge($bad, mdMutUnscoped($src));
    }
    expect($bad)->toBe([]);
});

it('documents every allowlisted class with a reason and skips only by exact class name', function () {
    foreach (MD_MUT_ALLOWLIST as $class => $reason) {
        expect($reason)->not->toBe('');
    }
    expect(array_keys(MD_MUT_ALLOWLIST))->toBe(['AdminSubjectQuery', 'SystemQuery']);

    $body = "{ DB::update('update medical_subject_members set level = 1'); }";
    expect(mdMutUnscoped("<?php class AdminSubjectQuery { public function a() $body }"))->toBe([])
        ->and(mdMutUnscoped("<?php class SystemQuery { public function a() $body }"))->toBe([])
        ->and(mdMutUnscoped("<?php class AdminSubjectQueryX { public function a() $body }"))->toBe(['AdminSubjectQueryX::a'])
        ->and(mdMutUnscoped("<?php class VisitQuery { public function a() $body }"))->toBe(['VisitQuery::a']);
});

it('detects an unscoped mutation and accepts the scoped one (scanner self-test)', function () {
    $unscoped = <<<'PHP'
<?php
class Fixture
{
    public function bump($id) { MedicalVisit::query()->whereKey($id)->update(['note' => 'x']); }
    public function read($id) { return MedicalVisit::query()->find($id); }
}
PHP;
    $scoped = str_replace('MedicalVisit::query()->whereKey', 'Visibility::apply(MedicalVisit::query(), $viewer)->whereKey', $unscoped);
    $upsert = "<?php class F2 { public function s() { DB::statement('INSERT INTO medical_allergies (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET a = 2'); } }";
    $upsert2 = "<?php class F3 { public function s() { MedicalAllergy::query()->upsert([], ['a']); } }";

    expect(mdMutUnscoped($unscoped))->toBe(['Fixture::bump'])
        ->and(mdMutUnscoped($scoped))->toBe([])
        ->and(mdMutUnscoped($upsert))->toBe(['F2::s'])
        ->and(mdMutUnscoped($upsert2))->toBe(['F3::s'])
        ->and(mdMutOutside(['Http/Controllers/Health/X.php' => $unscoped]))->toBe(['Http/Controllers/Health/X.php Fixture::bump'])
        ->and(mdMutOutside(['Medical/Queries/X.php' => $unscoped]))->toBe([]);
});

it('fails on a planted unscoped write in every non-allowlisted query class (mutation sanity)', function () {
    $classes = 0;
    foreach (mdMutSources('Medical/Queries') as $rel => $src) {
        $name = basename($rel, '.php');
        $pos = strrpos($src, '}');
        if ($pos === false || ! str_contains($src, 'class ') || array_key_exists($name, MD_MUT_ALLOWLIST)) {
            continue;
        }
        $classes++;
        $planted = [
            'builder update' => 'MedicalVisit::query()->whereKey($id)->update([\'note\' => \'x\']);',
            'raw sql' => 'DB::update(\'update medical_medications set note = ?\', [1]);',
            'upsert' => 'MedicalAllergy::query()->upsert([], [\'a\']);',
            'delete' => 'MedicalDocument::query()->whereKey($id)->delete();',
        ];
        foreach ($planted as $label => $statement) {
            $mutated = substr($src, 0, $pos).'    public function zzPlanted($id) { '.$statement." }\n".substr($src, $pos);
            expect(in_array($name.'::zzPlanted', mdMutUnscoped($mutated), true))->toBeTrue("$label not detected in $name");
        }
        // The same method with the scope passes, so the failure above is caused by the missing scope alone.
        $scoped = substr($src, 0, $pos).'    public function zzPlanted($id) { Visibility::apply(MedicalVisit::query(), $v)->update([\'note\' => \'x\']); }'."\n".substr($src, $pos);
        expect(mdMutUnscoped($scoped))->not->toContain($name.'::zzPlanted');
    }
    expect($classes)->toBeGreaterThanOrEqual(2);
});

it('keeps the medical disk and MedicalDocumentStore inside their allowed files and methods', function () {
    expect(mdStoreViolations(mdMutSources()))->toBe([]);
});

it('reports a planted file-store breach in each shape (scanner self-test)', function () {
    $disk = ['Http/Controllers/HomeController.php' => "<?php\nStorage::disk('medical')->get(\$p);\n"];
    $doubleQuoted = ['Http/Controllers/HomeController.php' => "<?php\n\$d = Storage::disk(\"medical\");\n"];
    $reference = ['Http/Controllers/Health/ExportController.php' => "<?php\nuse App\\Medical\\Documents\\MedicalDocumentStore;\n"];
    $deleteDir = ['Http/Controllers/Health/DocumentController.php' => "<?php\nclass D { public function __construct(private MedicalDocumentStore \$store) {} public function x(\$id) { \$this->store->deleteSubjectDirectory(\$id); } }"];
    $legitimate = [
        MD_STORE_FILE => "<?php\nStorage::disk('medical')->put(\$p, \$c);\n",
        'Http/Controllers/Health/DocumentController.php' => "<?php\nclass D { public function __construct(private MedicalDocumentStore \$store) {} public function x() { \$this->store->put(1, 2); \$this->store->absolutePath(1); \$this->store->delete(1); } }",
        'Medical/Queries/SubjectQuery.php' => "<?php\nclass S { public function x(MedicalDocumentStore \$store) { \$store->deleteSubjectDirectory(1); \$this->rows->delete(); } }",
        'Console/Commands/PruneMedicalOrphans.php' => "<?php\nclass P { public function handle(MedicalDocumentStore \$store) { \$store->files(); \$store->subjectDirectories(); \$store->delete(1); \$store->deleteSubjectDirectory(1); } }",
    ];
    $wrongCaller = [
        'Console/Commands/PruneMedicalOrphans.php' => "<?php\nclass P { public function handle(MedicalDocumentStore \$store) { \$store->put(1, 2); \$store->absolutePath(1); } }",
        'Medical/Queries/SubjectQuery.php' => "<?php\nclass S { public function x() { app(MedicalDocumentStore::class)->put(1, 2); } }",
    ];

    expect(mdStoreViolations($legitimate))->toBe([])
        ->and(mdStoreViolations($disk))->toBe(['Http/Controllers/HomeController.php: medical disk used outside MedicalDocumentStore'])
        ->and(mdStoreViolations($doubleQuoted))->toHaveCount(1)
        ->and(mdStoreViolations($reference))->toBe(['Http/Controllers/Health/ExportController.php: references MedicalDocumentStore'])
        ->and(mdStoreViolations($deleteDir))->toBe(['Http/Controllers/Health/DocumentController.php: calls store deleteSubjectDirectory()'])
        ->and(mdStoreViolations($wrongCaller))->toBe([
            'Console/Commands/PruneMedicalOrphans.php: calls store put()',
            'Console/Commands/PruneMedicalOrphans.php: calls store absolutePath()',
            'Medical/Queries/SubjectQuery.php: calls store put()',
        ]);
});
