<?php

/**
 * Static half of the mutation scope rule: a finance write must never change a row on an account the editor
 * cannot see. Rows are changed only inside App\Finance\Queries, and only by methods carrying Visibility::.
 */
const FH_MUT_TOKENS = ['->update(', '->delete(', '->forceDelete(', '->increment(', '->decrement(', '->save(', '->upsert(', 'upsert(', 'DB::update(', 'DB::delete(', '->updateOrInsert(', '->updateOrCreate('];

const FH_MUT_OUTSIDE = ['->update(', '->delete(', '->forceDelete(', '->increment(', '->decrement(', '->updateQuietly(', '->deleteQuietly(', '->upsert(', 'upsert(', '->updateOrInsert(', '->updateOrCreate('];

const FH_MUT_OWNED = [
    'finance_transactions', 'finance_recurring', 'finance_recurring_matches', 'finance_match_rejections',
    'finance_budgets', 'finance_import_batches', 'finance_import_rows',
    'FinanceTransaction', 'FinanceRecurring', 'FinanceRecurringMatch', 'FinanceMatchRejection',
    'FinanceBudget', 'FinanceImportBatch', 'FinanceImportRow',
];

const FH_MUT_ALLOWLIST = ['CategoryQuery::reassign', 'ImportQuery::pruneDrafts'];

/**
 * Splits PHP source into methods.
 *
 * @return list<array{class: string, method: string, body: string, doc: string}>
 */
function fhMutMethods(string $src): array
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
function fhMutHasToken(string $body, array $tokens): bool
{
    foreach ($tokens as $t) {
        if (str_contains($body, $t)) {
            return true;
        }
    }

    return false;
}

function fhMutIsMutation(string $body): bool
{
    return fhMutHasToken($body, FH_MUT_TOKENS)
        || preg_match('/\bupdate\s+"?finance_|\bdelete\s+from\s+"?finance_|\bdo\s+update\b/i', $body) === 1;
}

/**
 * Queries-layer rule: returns Class::method of every unscoped mutation (allowlist excluded).
 *
 * @return list<string>
 */
function fhMutUnscoped(string $src): array
{
    $bad = [];
    foreach (fhMutMethods($src) as $m) {
        $id = $m['class'].'::'.$m['method'];
        if (in_array($id, FH_MUT_ALLOWLIST, true)) {
            continue;
        }
        if (fhMutIsMutation($m['body']) && fhMutHasToken($m['body'], FH_MUT_OWNED) && ! str_contains($m['body'], 'Visibility::')) {
            $bad[] = $id;
        }
    }

    return $bad;
}

/** @return list<string> */
function fhMutFiles(string $sub): array
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

it('forbids row-changing calls in app/Finance outside the query layer', function () {
    $bad = [];
    foreach (fhMutFiles('Finance') as $file) {
        if (str_contains($file, '/app/Finance/Queries/')) {
            continue;
        }
        foreach (fhMutMethods(file_get_contents($file)) as $m) {
            if (fhMutHasToken($m['body'], FH_MUT_OUTSIDE)) {
                $bad[] = $m['class'].'::'.$m['method'];
            }
        }
    }
    expect($bad)->toBe([]);
});

it('requires the membership predicate in every query-layer method that mutates account-owned rows', function () {
    $bad = [];
    foreach (fhMutFiles('Finance/Queries') as $file) {
        $bad = array_merge($bad, fhMutUnscoped(file_get_contents($file)));
    }
    expect($bad)->toBe([]);
});

it('documents every existing allowlisted unscoped write', function () {
    $found = [];
    foreach (fhMutFiles('Finance/Queries') as $file) {
        foreach (fhMutMethods(file_get_contents($file)) as $m) {
            $id = $m['class'].'::'.$m['method'];
            if (in_array($id, FH_MUT_ALLOWLIST, true)) {
                $found[] = $id;
                expect(str_contains($m['doc'], 'Unscoped write:'))->toBeTrue($id.' needs an "Unscoped write:" docblock');
            }
        }
    }
    expect(array_diff($found, FH_MUT_ALLOWLIST))->toBe([]);
});

it('detects an unscoped mutation and accepts the scoped one (scanner self-test)', function () {
    $unscoped = <<<'PHP'
<?php
class Fixture
{
    public function bump($id) { FinanceTransaction::query()->whereKey($id)->update(['note' => 'x']); }
    public function read($id) { return FinanceTransaction::query()->find($id); }
}
PHP;
    $scoped = str_replace('FinanceTransaction::query()->whereKey', 'Visibility::apply(FinanceTransaction::query(), $viewer)->whereKey', $unscoped);
    $upsert = "<?php class F2 { public function s() { DB::statement('INSERT INTO finance_budgets (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET a = 2'); } }";
    $upsert2 = "<?php class F3 { public function s() { FinanceBudget::query()->upsert([], ['a']); } }";

    expect(fhMutUnscoped($unscoped))->toBe(['Fixture::bump'])
        ->and(fhMutUnscoped($scoped))->toBe([])
        ->and(fhMutUnscoped($upsert))->toBe(['F2::s'])
        ->and(fhMutUnscoped($upsert2))->toBe(['F3::s']);
});
