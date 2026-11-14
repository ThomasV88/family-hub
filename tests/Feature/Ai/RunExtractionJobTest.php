<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\SystemQuery;
use App\Models\AiAgentStatus;
use App\Models\AiCall;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

function ejPayload(): array
{
    return [
        'schema_version' => 1,
        'document_date' => '2026-03-04',
        'specialty_key' => 'cardiology',
        'visits' => [
            ['visited_on' => '2026-03-04', 'doctor' => 'Dr. Novak', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => 'Hypertension', 'notes' => 'Control in 3 months'],
            ['visited_on' => null, 'doctor' => null, 'specialty_key' => null, 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
        ],
        'medications' => [['name' => 'Enalapril', 'dose' => '10 mg', 'schedule' => 'daily', 'started_on' => '2026-03-04', 'ended_on' => null, 'notes' => null]],
        'allergies' => [['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'moderate']],
        'vaccinations' => [['vaccine' => 'Tetanus', 'given_on' => '2025-01-10', 'next_due_on' => '2035-01-10', 'notes' => null]],
    ];
}

/** A fake codex shell script with a custom body ($out holds the -o path). */
function ejScript(string $body): string
{
    $path = sys_get_temp_dir().'/ej-fake-'.bin2hex(random_bytes(6));
    file_put_contents($path, "#!/bin/sh\nout=; prev=; for a in \"\$@\"; do [ \"\$prev\" = \"-o\" ] && out=\"\$a\"; prev=\"\$a\"; done; ".$body."\n");
    chmod($path, 0700);

    return $path;
}

/**
 * A person with consent, an editing starter and one document with its queued run and call row.
 *
 * @return array{user: User, subject: MedicalSubject, doc: MedicalDocument, run: MedicalAiExtraction}
 */
function ejSetup(string $kind = 'image', ?string $bytes = null): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $doc = match ($kind) {
        'pdf' => MedicalDocument::factory()->pdf(),
        'heic' => MedicalDocument::factory()->heic(),
        default => MedicalDocument::factory()->image(),
    };
    $doc = $doc->forSubject($subject)->create(['title' => 'Scan']);
    Storage::disk('medical')->put($doc->path, $bytes ?? match ($kind) {
        'pdf' => MedicalFileFixtures::pdfPages(2),
        'heic' => MedicalFileFixtures::heic(),
        default => 'jpeg-bytes',
    });
    $run = ejQueue($doc, $user);

    return compact('user', 'subject', 'doc', 'run');
}

function ejQueue(MedicalDocument $doc, User $user): MedicalAiExtraction
{
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id]);
    AiCall::factory()->create([
        'extraction_id' => $run->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id, 'started_by' => $user->id,
    ]);

    return $run;
}

function ejRun(MedicalAiExtraction $run): void
{
    (new RunExtractionJob($run->id))->handle();
}

function ejUse(string $script): void
{
    $GLOBALS['ej_scripts'][] = $script;
    config(['ai.codex.binary' => $script]);
}

function ejRow(MedicalAiExtraction $run): object
{
    return DB::table('medical_ai_extractions')->where('id', $run->id)->first();
}

function ejCall(MedicalAiExtraction $run): object
{
    return DB::table('ai_calls')->where('extraction_id', $run->id)->first();
}

beforeEach(function () {
    Storage::fake('medical');
    $this->scripts = [];
    $this->root = sys_get_temp_dir().'/ej-work-'.strtolower((string) Str::ulid());
    $this->files = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null,
        'ai.timeouts.abort_check_seconds' => 0.2,
    ]);
});

afterEach(function () {
    foreach ([...($GLOBALS['ej_scripts'] ?? []), ...$this->files] as $f) {
        @unlink($f);
    }
    $GLOBALS['ej_scripts'] = [];
    putenv('OPENAI_API_KEY');
    unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

it('turns an image run into a ready proposal with call row, audit row and a clean work dir', function () {
    $s = ejSetup();
    ejUse(FakeCodex::succeeding(json_encode(ejPayload())));

    ejRun($s['run']);

    $row = ejRow($s['run']);
    expect($row->status)->toBe('ready')
        ->and(json_decode($row->payload, true))->toEqual(ejPayload())
        ->and($row->entry_count)->toBe(5)
        ->and($row->schema_version)->toBe(1)
        ->and($row->prompt_version)->toBe(1)
        ->and($row->model)->toBe('default')
        ->and($row->pages_read)->toBe(1)
        ->and($row->finished_at)->not->toBeNull();

    $call = ejCall($s['run']);
    expect($call->status)->toBe('succeeded')->and($call->duration_ms)->not->toBeNull()->and($call->model)->toBe('default');

    $audit = AuditEntry::query()->where('event', 'medical_ai_run_finished')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->meta)->toEqual(['subject' => $s['subject']->id, 'document' => $s['doc']->id, 'status' => 'succeeded'])
        ->and($audit[0]->actor_id)->toBe($s['user']->id);
    expect(glob($this->root.'/*'))->toBe([]);
    expect(AiAgentStatus::find('codex')->last_success_at)->not->toBeNull();
});

it('hands at most ten page images of a PDF to the CLI and never the PDF itself', function () {
    $s = ejSetup('pdf', MedicalFileFixtures::pdfPages(12));
    $record = sys_get_temp_dir().'/ej-rec-'.bin2hex(random_bytes(4));
    $this->files[] = $record;
    ejUse(FakeCodex::recording($record, json_encode(ejPayload())));

    ejRun($s['run']);

    $log = (string) file_get_contents($record);
    [$args, $rest] = explode('---ENV---', $log, 2);
    $ls = explode('---STDIN---', explode('---LS---', $rest, 2)[1], 2)[0];
    preg_match_all('/page-\d+\.jpg/', $args, $m);
    $row = ejRow($s['run']);

    expect(array_unique($m[0]))->toHaveCount(10)
        ->and($ls)->not->toContain('doc.pdf')
        ->and($ls)->toContain('page-10.jpg')
        ->and($row->status)->toBe('ready')
        ->and($row->pages_read)->toBe(10)
        ->and($row->pages_total)->toBe(12);
});

it('records the model the CLI reports, and default for a run ended before the spawn', function () {
    $s = ejSetup();
    ejUse(ejScript("cat >/dev/null; printf '%s\\n' '{\"type\":\"thread.started\",\"model\":\"gpt-test-1\"}'; printf %s ".escapeshellarg(json_encode(ejPayload())).' > "$out"'));
    ejRun($s['run']);

    expect(ejRow($s['run'])->model)->toBe('gpt-test-1')->and(ejCall($s['run'])->model)->toBe('gpt-test-1');

    $c = ejSetup();
    DB::table('medical_subjects')->where('id', $c['subject']->id)->update(['ai_consent' => false]);
    ejRun($c['run']);
    expect(ejRow($c['run'])->model)->toBe('default')->and(ejCall($c['run'])->model)->toBe('default');
});

it('supersedes the earlier ready run on success and keeps it when the re-run fails', function () {
    $s = ejSetup();
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(ejPayload(), 5)->create(['started_by' => $s['user']->id]);
    ejUse(FakeCodex::invalidJson());
    ejRun($s['run']);

    expect(ejRow($s['run'])->status)->toBe('failed')->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe('ready');

    $again = ejQueue($s['doc'], $s['user']);
    ejUse(FakeCodex::succeeding(json_encode(ejPayload())));
    ejRun($again);

    expect(ejRow($again)->status)->toBe('ready')
        ->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe('superseded');

    $third = ejQueue($s['doc'], $s['user']);
    DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]);
    ejRun($third);
    expect(ejRow($third)->status)->toBe('cancelled')->and(ejRow($again)->status)->toBe('ready');
});

it('claims once: a redelivered job exits without spawning anything', function () {
    $s = ejSetup();
    $record = sys_get_temp_dir().'/ej-rec-'.bin2hex(random_bytes(4));
    $this->files[] = $record;
    ejUse(FakeCodex::recording($record, json_encode(ejPayload())));

    ejRun($s['run']);
    expect(file_exists($record))->toBeTrue();
    unlink($record);
    ejRun($s['run']);

    expect(file_exists($record))->toBeFalse()->and(ejRow($s['run'])->status)->toBe('ready');
    expect(AuditEntry::query()->where('event', 'medical_ai_run_finished')->count())->toBe(1);
});

it('cancels before spawning when consent, membership, Health Edit, the starter or the run request is gone', function (string $case, string $reason) {
    $s = ejSetup();
    $record = sys_get_temp_dir().'/ej-rec-'.bin2hex(random_bytes(4));
    $this->files[] = $record;
    ejUse(FakeCodex::recording($record, json_encode(ejPayload())));

    match ($case) {
        'consent' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]),
        'membership' => DB::table('medical_subject_members')->where('subject_id', $s['subject']->id)->delete(),
        'module' => $s['user']->modulePermissions()->delete(),
        'view only' => $s['user']->modulePermissions()->update(['level' => AccessLevel::View]),
        'deleted' => $s['user']->delete(),
        'cancel' => DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['cancel_requested_at' => now()]),
    };

    ejRun($s['run']);

    $row = ejRow($s['run']);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe($reason)->and($row->payload)->toBeNull()
        ->and(file_exists($record))->toBeFalse()
        ->and(ejCall($s['run'])->status)->toBe('cancelled');
    $audit = AuditEntry::query()->where('event', 'medical_ai_run_finished')->first();
    expect($audit->meta['status'])->toBe('cancelled');
})->with([
    ['consent', 'consent'],
    ['membership', 'access'],
    ['module', 'access'],
    ['view only', 'access'],
    ['deleted', 'access'],
    ['cancel', 'user'],
]);

/** Consent reads false once the flag file exists; the fake CLI creates it. */
function ejFlagConsent(string $flag): void
{
    app()->instance(SystemQuery::class, new class($flag) extends SystemQuery
    {
        public function __construct(private string $flag) {}

        public function aiConsentFor(string $subjectId): bool
        {
            return ! file_exists($this->flag) && parent::aiConsentFor($subjectId);
        }
    });
}

it('kills a hanging CLI within seconds when consent is switched off during the run', function () {
    $s = ejSetup();
    $flag = sys_get_temp_dir().'/ej-flag-'.bin2hex(random_bytes(4));
    $this->files[] = $flag;
    ejFlagConsent($flag);
    ejUse(ejScript('cat >/dev/null; touch '.escapeshellarg($flag).'; while :; do sleep 1; done'));

    $start = microtime(true);
    ejRun($s['run']);

    expect(microtime(true) - $start)->toBeLessThan(20)
        ->and(ejRow($s['run'])->status)->toBe('cancelled')->and(ejRow($s['run'])->cancel_reason)->toBe('consent')
        ->and(ejCall($s['run'])->status)->toBe('cancelled')
        ->and(glob($this->root.'/*'))->toBe([]);
});

it('discards a result that arrives after consent was switched off', function () {
    $s = ejSetup();
    $flag = sys_get_temp_dir().'/ej-flag-'.bin2hex(random_bytes(4));
    $this->files[] = $flag;
    ejFlagConsent($flag);
    ejUse(ejScript('cat >/dev/null; touch '.escapeshellarg($flag).'; printf %s '.escapeshellarg(json_encode(ejPayload())).' > "$out"'));

    ejRun($s['run']);

    $row = ejRow($s['run']);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('consent')->and($row->payload)->toBeNull()
        ->and($row->entry_count)->toBeNull();
});

it('turns every bad outcome into a payload-less failure with a content-free code', function (string $kind, string $code) {
    $s = ejSetup();
    config(['ai.timeouts.process' => 2, 'ai.timeouts.idle' => 60]);
    ejUse(match ($kind) {
        'invalid' => FakeCodex::invalidJson(),
        'empty' => FakeCodex::emptyOutput(),
        'nofile' => FakeCodex::exitZeroNoFile(),
        'hang' => FakeCodex::hanging(),
        'auth' => FakeCodex::failing(1, '401 Unauthorized'),
    });

    ejRun($s['run']);

    $row = ejRow($s['run']);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe($code)->and($row->payload)->toBeNull()
        ->and(ejCall($s['run'])->failure_code)->toBe($code);
    expect(AiAgentStatus::find('codex')?->last_auth_failure_at !== null)->toBe($kind === 'auth');
    expect(glob($this->root.'/*'))->toBe([]);
})->with([
    ['invalid', 'invalid_output'],
    ['empty', 'no_output'],
    ['nofile', 'no_output'],
    ['hang', 'timeout'],
    ['auth', 'auth'],
]);

it('refuses to spawn when a per-token key is in the worker environment', function () {
    $s = ejSetup();
    $record = sys_get_temp_dir().'/ej-rec-'.bin2hex(random_bytes(4));
    $this->files[] = $record;
    ejUse(FakeCodex::recording($record, json_encode(ejPayload())));
    putenv('OPENAI_API_KEY=sk-test-not-real');

    ejRun($s['run']);

    expect(ejRow($s['run'])->failure_code)->toBe('preflight')->and(file_exists($record))->toBeFalse();
});

it('fails HEIC as unsupported and an unreadable PDF as unreadable without spawning', function () {
    $record = sys_get_temp_dir().'/ej-rec-'.bin2hex(random_bytes(4));
    $this->files[] = $record;
    ejUse(FakeCodex::recording($record, json_encode(ejPayload())));

    $h = ejSetup('heic');
    ejRun($h['run']);
    $p = ejSetup('pdf', 'not a pdf at all');
    ejRun($p['run']);

    expect(ejRow($h['run'])->failure_code)->toBe('unsupported_document')
        ->and(ejRow($p['run'])->failure_code)->toBe('unreadable_document')
        ->and(file_exists($record))->toBeFalse()
        ->and(glob($this->root.'/*'))->toBe([]);
});

it('goes to the ai queue on the database connection with the id as its only payload', function () {
    $job = new RunExtractionJob('0b0f2f3a-0000-4000-8000-000000000001');

    expect($job->connection)->toBe('database')->and($job->queue)->toBe('ai')
        ->and($job->tries)->toBe(1)->and($job->timeout)->toBe(330)->and($job->failOnTimeout)->toBeTrue()
        ->and(get_object_vars($job)['extractionId'])->toBe('0b0f2f3a-0000-4000-8000-000000000001');
});

it('closes a still-running row through failed() as worker_lost and ignores finished rows', function () {
    $s = ejSetup();
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'running', 'started_at' => now()]);
    DB::table('ai_calls')->where('extraction_id', $s['run']->id)->update(['status' => 'running', 'started_at' => now()]);

    (new RunExtractionJob($s['run']->id))->failed(new RuntimeException('boom'));

    expect(ejRow($s['run'])->failure_code)->toBe('worker_lost')->and(ejCall($s['run'])->status)->toBe('failed');

    ejUse(FakeCodex::succeeding(json_encode(ejPayload())));
    (new RunExtractionJob($s['run']->id))->failed(new RuntimeException('again'));
    expect(ejRow($s['run'])->status)->toBe('failed');
});

it('finishes as cancelled and keeps the old proposal when a cancel lands between the last recheck and the write', function () {
    $s = ejSetup();
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(ejPayload(), 5)->create(['started_by' => $s['user']->id]);
    ejUse(FakeCodex::succeeding(json_encode(ejPayload())));
    app()->instance(AiJobQuery::class, new class extends AiJobQuery
    {
        public function finishReady(string $id, array $payload, int $entryCount, ?int $pagesRead, ?int $pagesTotal, string $agent, string $model, int $durationMs): bool
        {
            DB::table('medical_ai_extractions')->where('id', $id)->update(['cancel_requested_at' => now()]);

            return parent::finishReady($id, $payload, $entryCount, $pagesRead, $pagesTotal, $agent, $model, $durationMs);
        }
    });

    ejRun($s['run']);

    $row = ejRow($s['run']);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('user')->and($row->payload)->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe('ready')
        ->and(ejCall($s['run'])->status)->toBe('cancelled');
    expect(AuditEntry::query()->where('event', 'medical_ai_run_finished')->first()->meta['status'])->toBe('cancelled');
});
