<?php

use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\MedicalPrivacy;

/*
 * Proof that the medical leak net (MedicalPrivacy, MedicalPrivacyTest, MedicalWriteParityTest) is complete and sensitive.
 * Completeness: the enumerated surfaces and write routes contain the whole route inventory of the phase.
 * Sensitivity (mutation sanity): throwaway routes named health.zz_mutant_* exist only inside these tests, run unscoped
 * statements on purpose, and each check of the net must catch them while the real surfaces stay green. Every raw
 * mutant statement carries a "zz_mutant" SQL comment so the test can tell the mutants' statements from real ones.
 */
beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

const MED_LS_GET_INVENTORY = [
    'health.index', 'health.people.show', 'health.documents.preview', 'health.documents.file',
    'health.exports.show', 'health.exports.print', 'health.exports.download', 'health.emergency.show',
];

/** Write routes that carry a person id (and mostly a record id): each answers a hidden id exactly like a random one. */
const MED_LS_PARITY_INVENTORY = [
    'health.people.update', 'health.people.destroy', 'health.people.ai_consent.update',
    'health.visits.store', 'health.visits.update', 'health.visits.destroy',
    'health.allergies.store', 'health.allergies.update', 'health.allergies.destroy',
    'health.medications.store', 'health.medications.update', 'health.medications.destroy',
    'health.vaccinations.store', 'health.vaccinations.update', 'health.vaccinations.destroy', 'health.vaccinations.given',
    'health.documents.store', 'health.documents.update', 'health.documents.destroy',
];

/** @return array<string, mixed> */
function medLsScenario(): array
{
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);

    return $ctx;
}

function medLsRefreshRoutes(): void
{
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/** Registers the read mutants a missing scope would look like (all GET, all behind the ordinary health view gate). */
function medLsRegisterReadMutants(): void
{
    Route::middleware(['web', 'access:module,health,view'])->group(function () {
        Route::get('/health/zz-mutant-count', fn () => response((string) DB::selectOne('select count(*) as n from medical_visits /* zz_mutant */')->n))
            ->name('health.zz_mutant_count');
        Route::get('/health/zz-mutant-vaccination-count', fn () => response()->json(
            DB::select('select subject_id, count(*) as n from medical_vaccinations group by subject_id /* zz_mutant */'),
        ))->name('health.zz_mutant_vaccination_count');
        // Mentions the members table but does not constrain it to the viewer: a "text appears" check would let this pass.
        Route::get('/health/zz-mutant-weak-scope', fn () => response()->json(
            DB::select('select subject_id, count(*) as n from medical_vaccinations where subject_id in (select subject_id from medical_subject_members) group by subject_id /* zz_mutant */'),
        ))->name('health.zz_mutant_weak_scope');
        // Streams one document (the last by title) with no subject filter, the title in the download name.
        Route::get('/health/zz-mutant-file', function () {
            $row = DB::selectOne('select path, title from medical_documents order by title desc limit 1 /* zz_mutant */');
            $bytes = Storage::disk('medical')->exists($row->path) ? Storage::disk('medical')->get($row->path) : '';

            return response($bytes, 200, ['Content-Disposition' => 'inline; filename="'.$row->title.'"']);
        })->name('health.zz_mutant_file');
        // Renders every subject (names, canary) like an export that forgot its subject filter.
        Route::get('/health/zz-mutant-export', fn () => response()->json(
            DB::select('select id, name from medical_subjects /* zz_mutant */'),
        ))->name('health.zz_mutant_export');
    });
    medLsRefreshRoutes();
}

it('enumerates every GET route of the phase inventory as a leak surface', function () {
    $names = array_map(fn ($r) => $r->getName(), MedicalPrivacy::surfaces());

    expect(array_diff(MED_LS_GET_INVENTORY, $names))->toBe([])
        ->and($names)->toContain('home', 'settings.index', 'settings.members');
    foreach ($names as $name) {
        expect(str_starts_with($name, 'health.') || in_array($name, ['home', 'settings.index', 'settings.members'], true))->toBeTrue("unexpected surface $name");
    }
});

it('covers every record-id write route with hidden-vs-random parity and every health write with the scoped-mutation check', function () {
    $ctx = medLsScenario();

    $parity = [];
    $mutation = [];
    foreach (MedicalPrivacy::writes() as $route) {
        if (MedicalPrivacy::hiddenParams($route, $ctx) !== null) {
            $parity[] = $route->getName();
        }
        MedicalPrivacy::params($route, $ctx); // throws when a parameter has no provider
        $mutation[] = $route->getName();
    }

    expect($parity)->toContain(...MED_LS_PARITY_INVENTORY)
        ->and(count($parity))->toBeGreaterThanOrEqual(19)
        ->and(array_values(array_diff($mutation, $parity)))->toBe(['health.people.store']) // the only id-free write
        ->and(count($mutation))->toBeGreaterThanOrEqual(20);
});

it('throws for a new health GET route with a parameter nobody provided', function () {
    $ctx = medLsScenario();
    Route::middleware(['web', 'access:module,health,view'])->get('/health/zz-unknown/{zzparam}', fn () => 'x')->name('health.zz_unknown');
    medLsRefreshRoutes();

    $route = collect(MedicalPrivacy::surfaces())->first(fn ($r) => $r->getName() === 'health.zz_unknown');
    expect($route)->not->toBeNull()
        ->and(fn () => MedicalPrivacy::urls($route, $ctx))->toThrow(RuntimeException::class, 'zzparam');
});

it('catches unscoped read, aggregate, weak-scope, file and export surfaces while the real surfaces stay clean', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    medLsRegisterReadMutants();

    $before = MedicalPrivacy::capture($this, $ctx['viewer'], $ctx);
    MedicalPrivacy::seedHidden($ctx);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $after = MedicalPrivacy::capture($this, $ctx['viewer'], $ctx);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    $isMutant = fn ($body, string $key) => str_contains($key, 'zz-mutant');
    $realBefore = array_filter($before, fn ($b, $k) => ! $isMutant($b, $k), ARRAY_FILTER_USE_BOTH);
    $realAfter = array_filter($after, fn ($b, $k) => ! $isMutant($b, $k), ARRAY_FILTER_USE_BOTH);
    $mutantKeys = array_keys(array_filter($after, $isMutant, ARRAY_FILTER_USE_BOTH));

    expect($mutantKeys)->toHaveCount(10)->and($realAfter)->not->toBeEmpty();

    // Real surfaces stay green: identical before and after, no canary.
    expect($realAfter)->toEqual($realBefore);
    foreach ($realAfter as $key => $body) {
        foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
            expect(str_contains(MedicalPrivacy::scannable($body), $needle))->toBeFalse("canary '$needle' in real surface $key");
        }
    }

    $hiddenPerson = $ctx['hidden']['person'];

    // (a) differential: the unscoped count changes when hidden data appears.
    foreach (['inertia', 'html'] as $kind) {
        expect($after["$kind /health/zz-mutant-count"])->not->toBe($before["$kind /health/zz-mutant-count"], "differential blind to $kind count mutant");
    }
    // (b) aggregate: grouped count per subject changes the output and prints the hidden subject id.
    expect($after['html /health/zz-mutant-vaccination-count'])->not->toBe($before['html /health/zz-mutant-vaccination-count'])
        ->and(MedicalPrivacy::scannable($after['html /health/zz-mutant-vaccination-count']))->toContain($hiddenPerson);
    // (c) weak scope: the members table without the viewer predicate leaks the same ids and is flagged.
    expect(MedicalPrivacy::scannable($after['html /health/zz-mutant-weak-scope']))->toContain($hiddenPerson);
    // (d) file mutant: the canary title travels in the download name and the differential sees the other bytes.
    expect(MedicalPrivacy::scannable($after['html /health/zz-mutant-file']))->toContain(MedicalPrivacy::CANARY)
        ->and($after['html /health/zz-mutant-file'])->not->toBe($before['html /health/zz-mutant-file']);
    // (e) export mutant: the canary subject name is in the body.
    expect(MedicalPrivacy::scannable($after['html /health/zz-mutant-export']))->toContain(MedicalPrivacy::CANARY);

    // Query log: the membership predicate is missing, and the only statements missing it are the mutants'.
    $unscoped = MedicalPrivacy::unscopedReads($statements);
    expect($unscoped)->not->toBeEmpty();
    foreach ($unscoped as $sql) {
        expect(str_contains($sql, 'zz_mutant'))->toBeTrue('a real statement is unscoped: '.$sql);
    }
    $has = fn (string $needle) => collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), $needle));
    expect($has('count(*) as n from medical_visits'))->toBeTrue()
        ->and($has('from medical_vaccinations group by'))->toBeTrue()
        ->and($has('medical_subject_members) group by'))->toBeTrue()
        ->and($has('from medical_documents order by title desc'))->toBeTrue()
        ->and($has('from medical_subjects /*'))->toBeTrue();
});

it('keeps mutant routes out of the application', function () {
    $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())->filter(fn ($n) => str_contains($n, 'zz_'));
    expect($names->all())->toBe([])
        ->and(str_contains((string) file_get_contents(base_path('routes/web.php')), 'zz_'))->toBeFalse();
    foreach (glob(base_path('routes/web/*.php')) ?: [] as $file) {
        expect(str_contains((string) file_get_contents($file), 'zz_'))->toBeFalse("$file");
    }
});

it('fails the scoped-mutation check for an unscoped UPDATE, INSERT ... SELECT and DELETE write route (mutation sanity)', function () {
    $ctx = medLsScenario();
    $hiddenAllergy = $ctx['hidden']['allergy'];
    $hiddenVisit = $ctx['hidden']['visit'];
    $hiddenPerson = $ctx['hidden']['person'];
    Route::middleware(['web', 'access:module,health,edit'])->group(function () use ($hiddenAllergy, $hiddenVisit, $hiddenPerson) {
        Route::post('/health/zz-mutant-update', function () use ($hiddenAllergy) {
            DB::update('update medical_allergies set reaction = ? where id = ?', ['tampered', $hiddenAllergy]);

            return response('ok');
        })->name('health.zz_mutant_update');
        Route::post('/health/zz-mutant-insert-select', function () use ($hiddenPerson) {
            DB::insert('insert into medical_contacts (id, subject_id, name, phone, sort, created_at, updated_at) '
                .'select gen_random_uuid(), subject_id, name, phone, 1, now(), now() from medical_contacts where subject_id = ? limit 1 on conflict do nothing', [$hiddenPerson]);

            return response('ok');
        })->name('health.zz_mutant_insert_select');
        Route::post('/health/zz-mutant-delete', function () use ($hiddenVisit) {
            DB::table('medical_visits')->where('id', $hiddenVisit)->delete();

            return response('ok');
        })->name('health.zz_mutant_delete');
    });
    medLsRefreshRoutes();

    $mutants = collect(MedicalPrivacy::writes())->filter(fn ($r) => str_contains($r->getName(), 'zz_mutant'))->map->getName()->values()->all();
    expect($mutants)->toHaveCount(3);

    foreach (['zz-mutant-update', 'zz-mutant-insert-select', 'zz-mutant-delete'] as $path) {
        $run = function () use ($ctx, $path) {
            MedicalPrivacy::assertScopedMutations(function () use ($ctx, $path) {
                app('auth')->forgetGuards();
                $this->flushSession();
                $this->actingAs($ctx['viewer'])->post('/health/'.$path);
            });
        };
        expect($run)->toThrow(AssertionFailedError::class, 'Unscoped medical mutation');
    }
});

it('does not name a medical subject the viewer cannot see in the hidden fixtures the mutants run against', function () {
    $ctx = medLsScenario();

    expect(MedicalSubject::query()->whereKey($ctx['hidden']['person'])->exists())->toBeTrue()
        ->and(MedicalDocument::query()->count())->toBeGreaterThanOrEqual(3);
});
