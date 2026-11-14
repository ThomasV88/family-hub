<?php

// Medical models and queries live in the medical layers only (the Health form requests are part of the layer, like
// App\Http\Requests\Finance; the plan list omitted them although VisitRequest resolves ids through SubjectQuery). Namespaces that do not exist yet match nothing, so later
// plans are covered the moment they add classes.
$mdModels = array_map(
    fn (string $f) => 'App\Models\\'.basename($f, '.php'),
    glob(dirname(__DIR__, 3).'/app/Models/Medical*.php') ?: [],
);

arch('medical models are used only by the medical layers and factories')
    ->expect($mdModels)
    ->toOnlyBeUsedIn([
        'App\Medical',
        'App\Http\Controllers\Health',
        'App\Http\Requests\Health',
        'App\Models',
        'Database\Factories',
        'Tests',
    ]);

// MembersController is the single Settings exception outside HealthPeopleController, reason: "admin person names prop
// only" (07-07 adds the healthPeople prop from AdminSubjectQuery::listForAdmin()). The source scan below pins it to
// that one class and that one method.
arch('medical queries are used only by the medical layers')
    ->expect('App\Medical\Queries')
    ->toOnlyBeUsedIn([
        'App\Medical',
        'App\Http\Controllers\Health',
        'App\Http\Controllers\Settings\HealthPeopleController',
        'App\Http\Controllers\Settings\MembersController',
        // admin AI call log, names only (08-14); pinned to AdminAiCallQuery below
        'App\Http\Controllers\Settings\AiCallsController',
        'App\Http\Requests\Health',
        'App\Http\Requests\Settings\HealthPeople',
        'App\Console\Commands',
        'Tests',
    ]);

arch('the home page and the Inertia shared props never touch medical code')
    ->expect(['App\Http\Controllers\HomeController', 'App\Http\Middleware\HandleInertiaRequests'])
    ->not->toUse(['App\Medical', 'App\Models\MedicalSubject']);

/** @return array<string, string> path relative to app/ => source */
function mdArchSources(string $sub): array
{
    $root = dirname(__DIR__, 3).'/app/';
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

const MD_ARCH_MEDICAL_REF = '/App\\\\(?:Medical\b|Models\\\\Medical)/';

/**
 * Settings controllers other than HealthPeopleController and MembersController must not reference medical code at all.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function mdArchSettingsLeaks(array $sources): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        // AiCallsController (08-14) is exempt here and pinned separately by mdArchAiCallsViolations().
        if (in_array($path, ['Http/Controllers/Settings/HealthPeopleController.php', 'Http/Controllers/Settings/MembersController.php', 'Http/Controllers/Settings/AiCallsController.php'], true)) {
            continue;
        }
        if (preg_match(MD_ARCH_MEDICAL_REF, $src) === 1) {
            $bad[] = $path;
        }
    }

    return $bad;
}

/**
 * The notification layer may reference medical code only through the outbox registry naming the vaccination handler.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function mdArchNotificationLeaks(array $sources): array
{
    $bad = [];
    foreach ($sources as $path => $src) {
        $stripped = $path === 'Notifications/Outbox/OutboxHandlers.php'
            ? str_replace('App\Medical\Reminders\VaccinationDueHandler', '', $src)
            : $src;
        if (preg_match(MD_ARCH_MEDICAL_REF, $stripped) === 1) {
            $bad[] = $path;
        }
    }

    return $bad;
}

/**
 * MembersController may depend only on App\Medical\Queries\AdminSubjectQuery and call only listForAdmin on it.
 *
 * @return list<string>
 */
function mdArchMembersViolations(string $src): array
{
    $bad = [];
    preg_match_all('/^use\s+(App\\\\(?:Medical|Models\\\\Medical)[\\\\\w]*)\s*;/m', $src, $uses);
    foreach ($uses[1] as $import) {
        if ($import !== 'App\Medical\Queries\AdminSubjectQuery') {
            $bad[] = 'imports '.$import;
        }
    }
    if (preg_match('/App\\\\Medical\\\\(?!Queries\\\\AdminSubjectQuery\b)\w/', $src) === 1) {
        $bad[] = 'references another App\Medical class';
    }
    preg_match_all('/AdminSubjectQuery\s+\$(\w+)/', $src, $vars);
    $calls = [];
    foreach (array_unique($vars[1]) as $var) {
        preg_match_all('/\$(?:this->)?'.preg_quote($var, '/').'\s*->\s*(\w+)\s*\(/', $src, $m);
        $calls = array_merge($calls, $m[1]);
    }
    preg_match_all('/AdminSubjectQuery::(?!class\b)(\w+)/', $src, $static);
    preg_match_all('/AdminSubjectQuery::class\s*\)\s*->\s*(\w+)\s*\(/', $src, $resolved);
    foreach (array_merge($calls, $static[1], $resolved[1]) as $method) {
        if ($method !== 'listForAdmin') {
            $bad[] = 'calls '.$method;
        }
    }

    return $bad;
}

it('lets no Settings controller but HealthPeopleController and MembersController reference medical code', function () {
    expect(mdArchSettingsLeaks(mdArchSources('Http/Controllers/Settings')))->toBe([])
        ->and(mdArchSettingsLeaks(['Http/Controllers/Settings/AuditController.php' => 'use App\Medical\Queries\SubjectQuery;']))->toBe(['Http/Controllers/Settings/AuditController.php'])
        ->and(mdArchSettingsLeaks(['Http/Controllers/Settings/PermissionsController.php' => 'use App\Models\MedicalSubject;']))->toBe(['Http/Controllers/Settings/PermissionsController.php'])
        ->and(mdArchSettingsLeaks(['Http/Controllers/Settings/MembersController.php' => 'use App\Medical\Queries\AdminSubjectQuery;']))->toBe([]);
});

it('keeps the outbox registry the only notification file naming medical code, and only the vaccination handler', function () {
    expect(mdArchNotificationLeaks(mdArchSources('Notifications')))->toBe([])
        ->and(mdArchNotificationLeaks(['Notifications/Outbox/OutboxHandlers.php' => "['x' => App\Medical\Reminders\VaccinationDueHandler::class]"]))->toBe([])
        ->and(mdArchNotificationLeaks(['Notifications/Outbox/OutboxHandlers.php' => 'App\Medical\Queries\SystemQuery::class']))->toBe(['Notifications/Outbox/OutboxHandlers.php'])
        ->and(mdArchNotificationLeaks(['Notifications/Outbox/OutboxWorker.php' => 'App\Medical\Reminders\VaccinationDueHandler::class']))->toBe(['Notifications/Outbox/OutboxWorker.php']);
});

it('pins MembersController to AdminSubjectQuery::listForAdmin as the only medical dependency', function () {
    $src = mdArchSources('Http/Controllers/Settings')['Http/Controllers/Settings/MembersController.php'] ?? '';
    expect($src)->not->toBe('')
        ->and(mdArchMembersViolations($src))->toBe([]);

    $ok = "<?php\nuse App\Medical\Queries\AdminSubjectQuery;\nclass M { public function index(AdminSubjectQuery \$q) { return \$q->listForAdmin(); } }";
    expect(mdArchMembersViolations($ok))->toBe([])
        ->and(mdArchMembersViolations(str_replace('listForAdmin', 'find', $ok)))->toBe(['calls find'])
        ->and(mdArchMembersViolations("<?php\nuse App\Medical\Queries\AdminSubjectQuery;\nclass M { function a() { return AdminSubjectQuery::find(1); } }"))->toBe(['calls find'])
        ->and(mdArchMembersViolations("<?php\nuse App\Medical\Queries\AdminSubjectQuery;\nclass M { function a() { return app(AdminSubjectQuery::class)->update(); } }"))->toBe(['calls update'])
        ->and(mdArchMembersViolations($ok."\nuse App\Medical\Visibility;"))->toContain('imports App\Medical\Visibility')
        ->and(mdArchMembersViolations($ok."\nuse App\Models\MedicalSubject;"))->toContain('imports App\Models\MedicalSubject');
});

/**
 * AiCallsController (admin AI call log, 08-14) may reference only AdminAiCallQuery (the one Queries class), plus
 * AgentHealth and CheckLoginJob for login health. No other App\Medical class and no medical model.
 *
 * @return list<string>
 */
function mdArchAiCallsViolations(string $src): array
{
    $bad = [];
    $allowed = ['App\Medical\Queries\AdminAiCallQuery', 'App\Medical\Ai\AgentHealth', 'App\Medical\Ai\CheckLoginJob'];
    preg_match_all('/App\\\\(?:Medical\b|Models\\\\Medical)[\\\\\w]*/', $src, $refs);
    foreach (array_unique($refs[0]) as $ref) {
        if (! in_array($ref, $allowed, true)) {
            $bad[] = 'references '.$ref;
        }
    }

    return $bad;
}

it('pins AiCallsController to AdminAiCallQuery, AgentHealth and CheckLoginJob', function () {
    $src = mdArchSources('Http/Controllers/Settings')['Http/Controllers/Settings/AiCallsController.php'] ?? '';
    expect($src)->not->toBe('')
        ->and(mdArchAiCallsViolations($src))->toBe([])
        ->and(mdArchAiCallsViolations("<?php\nuse App\\Medical\\Queries\\SubjectQuery;"))->toBe(['references App\\Medical\\Queries\\SubjectQuery'])
        ->and(mdArchAiCallsViolations("<?php\nuse App\\Models\\MedicalSubject;"))->toBe(['references App\\Models\\MedicalSubject'])
        ->and(mdArchAiCallsViolations("<?php\nuse App\\Medical\\Queries\\AdminAiCallQuery;"))->toBe([]);
});
