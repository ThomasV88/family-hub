<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\AiAgentSetting;
use App\Models\AiBulkRun;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

function txStepPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => '2026-03-04', 'specialty_key' => 'cardiology',
        'visits' => [], 'medications' => [['name' => 'Enalapril', 'dose' => '10 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

/**
 * A person with consent, an editing starter and a queued run of $mode over a document of $kind with $pages pages.
 *
 * @return array{user: User, subject: mixed, doc: MedicalDocument, run: MedicalAiExtraction}
 */
function txStepSetup(string $mode, string $kind = 'image', int $pages = 1): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $factory = match ($kind) {
        'pdf' => MedicalDocument::factory()->pdf(),
        'heic' => MedicalDocument::factory()->heic(),
        default => MedicalDocument::factory()->image(),
    };
    $doc = $factory->forSubject($subject)->create(['title' => 'Scan']);
    Storage::disk('medical')->put($doc->path, match ($kind) {
        'pdf' => MedicalFileFixtures::pdfPages($pages),
        'heic' => MedicalFileFixtures::heic(),
        default => 'jpeg-bytes',
    });
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id, 'mode' => $mode]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id, 'started_by' => $user->id]);

    return compact('user', 'subject', 'doc', 'run');
}

/** Installs a transcribing fake codex and returns the log path. */
function txStepFake(object $test, array $options = [], string $prefix = 'Page'): string
{
    $log = sys_get_temp_dir().'/tx-log-'.bin2hex(random_bytes(4));
    $state = sys_get_temp_dir().'/tx-state-'.bin2hex(random_bytes(4));
    $script = FakeCodex::transcribing($state, $log, $prefix, $options + ['extract' => json_encode(txStepPayload())]);
    array_push($test->files, $script, $log, $state);
    config(['ai.codex.binary' => $script]);

    return $log;
}

/** @return list<string> one entry per CLI call: "T <images>" or "X" */
function txStepCalls(string $log): array
{
    return is_file($log) ? array_values(array_filter(array_map('trim', file($log) ?: []))) : [];
}

function txStepRun(MedicalAiExtraction $run): void
{
    (new RunExtractionJob($run->id))->handle();
}

function txStepRow(MedicalAiExtraction $run): object
{
    return DB::table('medical_ai_extractions')->where('id', $run->id)->first();
}

function txStepText(MedicalDocument $doc): ?object
{
    return DB::table('medical_document_texts')->where('document_id', $doc->id)->first();
}

/** @return list<object> */
function txStepCallRows(MedicalAiExtraction $run): array
{
    return DB::table('ai_calls')->where('extraction_id', $run->id)->orderBy('attempt')->get()->all();
}

beforeEach(function () {
    Storage::fake('medical');
    $this->files = [];
    $this->root = sys_get_temp_dir().'/tx-work-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null,
        'ai.timeouts.abort_check_seconds' => 0.2,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
    ]);
});

afterEach(function () {
    foreach ($this->files as $f) {
        @unlink($f);
    }
    foreach ([$this->root, $this->root.'-copilot', $this->root.'-cache'] as $dir) {
        if (is_dir($dir)) {
            WorkDir::removeTree($dir);
        }
    }
});

it('keeps mode entries to the single image-to-entries call and writes no text', function () {
    $s = txStepSetup('entries');
    $log = txStepFake($this);

    txStepRun($s['run']);

    expect(txStepCalls($log))->toBe(['X'])->and(txStepRow($s['run'])->status)->toBe('ready')
        ->and(txStepText($s['doc']))->toBeNull()->and(txStepCallRows($s['run']))->toHaveCount(1);
});

it('transcribes an image in mode full, stores the text for this run, then reads the entries', function () {
    $s = txStepSetup('full');
    $log = txStepFake($this);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    $row = txStepRow($s['run']);
    $calls = txStepCallRows($s['run']);
    expect(txStepCalls($log))->toBe(['T 1', 'X'])
        ->and($text->body)->toBe('Page 1')->and((int) $text->pages_read)->toBe(1)->and((int) $text->pages_total)->toBe(1)->and($text->complete)->toBeTrue()
        ->and($text->extraction_id)->toBe($s['run']->id)->and($text->language)->toBe('sk')
        ->and($row->status)->toBe('ready')->and((int) $row->pages_read)->toBe(1)->and((int) $row->pages_total)->toBe(1)
        ->and($calls)->toHaveCount(2)->and([$calls[0]->status, $calls[1]->status])->toBe(['succeeded', 'succeeded']);
});

it('transcribes a nine page PDF in batches of 4, 4 and 1 and ends a text run as text_saved', function () {
    $s = txStepSetup('text', 'pdf', 9);
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(['entries' => []], 0)->create(['started_by' => $s['user']->id, 'queued_at' => now()->subDay()]);
    $log = txStepFake($this);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    $row = txStepRow($s['run']);
    expect(txStepCalls($log))->toBe(['T 4', 'T 4', 'T 1'])
        ->and(explode("\x0c", $text->body))->toBe(array_map(fn ($n) => "Page $n", range(1, 9)))
        ->and($text->complete)->toBeTrue()->and((int) $text->pages_total)->toBe(9)
        ->and($row->status)->toBe('text_saved')->and((int) $row->pages_read)->toBe(9)->and($row->payload)->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe('ready')
        ->and(array_map(fn ($c) => $c->status, txStepCallRows($s['run'])))->toBe(['succeeded', 'succeeded', 'succeeded']);
    expect(DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->count())->toBe(1);
});

it('reads at most 20 pages of a longer PDF in five batches and marks the text partial', function () {
    $s = txStepSetup('text', 'pdf', 25);
    $log = txStepFake($this);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    expect(txStepCalls($log))->toBe(['T 4', 'T 4', 'T 4', 'T 4', 'T 4'])
        ->and((int) $text->pages_read)->toBe(20)->and((int) $text->pages_total)->toBe(25)->and($text->complete)->toBeFalse()
        ->and(txStepRow($s['run'])->status)->toBe('text_saved');
});

it('stores the text after every batch, so a failing second batch leaves pages 1 to 4', function () {
    $s = txStepSetup('text', 'pdf', 9);
    $log = txStepFake($this, ['fail_nth' => 2]);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    $calls = txStepCallRows($s['run']);
    expect(txStepCalls($log))->toBe(['T 4', 'T 4'])
        ->and((int) $text->pages_read)->toBe(4)->and($text->complete)->toBeFalse()->and(explode("\x0c", $text->body))->toBe(['Page 1', 'Page 2', 'Page 3', 'Page 4'])
        ->and(txStepRow($s['run'])->status)->toBe('text_saved')->and((int) txStepRow($s['run'])->pages_read)->toBe(4)
        ->and([$calls[0]->status, $calls[1]->status, $calls[1]->failure_code])->toBe(['succeeded', 'failed', 'invalid_output']);
});

it('goes on to the entry extraction in mode full when a later batch fails', function () {
    $s = txStepSetup('full', 'pdf', 9);
    $log = txStepFake($this, ['fail_nth' => 2]);

    txStepRun($s['run']);

    expect(txStepCalls($log))->toBe(['T 4', 'T 4', 'X'])->and(txStepRow($s['run'])->status)->toBe('ready')
        ->and((int) txStepRow($s['run'])->pages_read)->toBe(4)->and((int) txStepRow($s['run'])->pages_total)->toBe(9)
        ->and((int) txStepText($s['doc'])->pages_read)->toBe(4);
});

it('fails the run when the very first batch is invalid and stores nothing', function (string $mode) {
    $s = txStepSetup($mode, 'pdf', 9);
    $log = txStepFake($this, ['fail_nth' => 1]);

    txStepRun($s['run']);

    expect(txStepCalls($log))->toBe(['T 4'])->and(txStepRow($s['run'])->status)->toBe('failed')->and(txStepRow($s['run'])->failure_code)->toBe('invalid_output')
        ->and(txStepText($s['doc']))->toBeNull();
})->with(['text', 'full']);

it('stops after the first batch when the budget cannot hold another, and mode full still reads the entries', function () {
    config(['ai.ocr.seconds_per_page' => 1000]);
    $s = txStepSetup('full', 'pdf', 9);
    $log = txStepFake($this);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    expect(txStepCalls($log))->toBe(['T 4', 'X'])->and((int) $text->pages_read)->toBe(4)->and((int) $text->pages_total)->toBe(9)->and($text->complete)->toBeFalse()
        ->and(txStepRow($s['run'])->status)->toBe('ready');
});

it('parks on a usage limit with the first batch stored and resumes at the next unread page', function () {
    $s = txStepSetup('text', 'pdf', 9);
    $log = txStepFake($this, ['fail_nth' => 2, 'fail_kind' => 'quota']);

    txStepRun($s['run']);

    expect(txStepRow($s['run'])->status)->toBe('waiting')->and((int) txStepText($s['doc'])->pages_read)->toBe(4)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $this->travelTo(now()->addHours(2));
    expect(Artisan::call('ai:resume-waiting'))->toBe(0);
    expect(txStepRow($s['run'])->status)->toBe('queued');
    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    expect(txStepCalls($log))->toBe(['T 4', 'T 4', 'T 4', 'T 1'])
        ->and(explode("\x0c", $text->body))->toBe(array_map(fn ($n) => "Page $n", range(1, 9)))
        ->and($text->complete)->toBeTrue()->and(txStepRow($s['run'])->status)->toBe('text_saved')->and((int) txStepRow($s['run'])->pages_read)->toBe(9);
});

it('hands a batch to Copilot after a Codex usage limit, through the inline prompt', function () {
    $s = txStepSetup('text', 'pdf', 6);
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->update(['providers' => 'codex,copilot']);
    $log = txStepFake($this, ['fail_nth' => 1, 'fail_kind' => 'quota']);
    $page = fn (int $n) => ['page' => $n, 'legible' => true, 'text' => "Copilot $n"];
    $first = json_encode(['schema_version' => 1, 'language' => 'en', 'pages' => [$page(1), $page(2), $page(3), $page(4)]]);
    $second = json_encode(['schema_version' => 1, 'language' => 'en', 'pages' => [$page(1), $page(2)]]);
    $counter = sys_get_temp_dir().'/tx-cop-'.bin2hex(random_bytes(4));
    $this->files[] = $script = FakeCopilot::sequence([$first, $second], $counter);
    $this->files[] = $counter;
    config(['ai.copilot.binary' => $script]);

    txStepRun($s['run']);

    $text = txStepText($s['doc']);
    expect(txStepCalls($log))->toBe(['T 4'])->and(txStepRow($s['run'])->status)->toBe('text_saved')
        ->and(explode("\x0c", $text->body))->toBe(['Copilot 1', 'Copilot 2', 'Copilot 3', 'Copilot 4', 'Copilot 1', 'Copilot 2'])
        ->and($text->agent)->toBe('copilot')->and($text->language)->toBe('en');
    $prompt = (string) file_get_contents($counter.'.p1');
    expect($prompt)->toContain('Transcribe all the text')->and($prompt)->toContain('JSON Schema');
    foreach (glob($counter.'*') ?: [] as $f) {
        $this->files[] = $f;
    }
});

it('never gives a bulk run the Copilot fallback', function () {
    $s = txStepSetup('text', 'pdf', 6);
    $bulk = AiBulkRun::factory()->create(['providers' => 'codex,copilot']);
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['bulk_run_id' => $bulk->id]);
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    $s['user']->forceFill(['is_admin' => true])->save();
    $log = txStepFake($this, ['fail_nth' => 1, 'fail_kind' => 'quota']);
    $counter = sys_get_temp_dir().'/tx-cop-'.bin2hex(random_bytes(4));
    $this->files[] = $script = FakeCopilot::sequence(['{}'], $counter);
    $this->files[] = $counter;
    config(['ai.copilot.binary' => $script, 'ai.bulk.fallback_agents' => false]);

    txStepRun($s['run']);

    expect(txStepCalls($log))->toBe(['T 4'])->and(is_file($counter))->toBeFalse()->and(txStepRow($s['run'])->status)->toBe('waiting')
        ->and(txStepText($s['doc']))->toBeNull();
});

it('cancels on consent withdrawn between two batches and leaves no text', function () {
    $s = txStepSetup('text', 'pdf', 9);
    $log = txStepFake($this);
    $inserts = 0;
    $busy = false;
    DB::listen(function ($q) use (&$inserts, &$busy, $s) {
        if (! $busy && str_starts_with($q->sql, 'insert into "ai_calls"') && ++$inserts === 1) {
            // The second batch has just opened its call row: an editor withdraws consent now (and the text goes with it).
            $busy = true;
            app(SubjectQuery::class)->setConsent(Viewer::for($s['user']), $s['subject']->fresh(), false);
        }
    });

    txStepRun($s['run']);

    expect(txStepRow($s['run'])->status)->toBe('cancelled')->and(txStepRow($s['run'])->cancel_reason)->toBe('consent')
        ->and(txStepText($s['doc']))->toBeNull()->and(count(txStepCalls($log)))->toBeLessThanOrEqual(2);
});

it('cancels as access when the document is archived between two batches and stores nothing more', function () {
    $s = txStepSetup('text', 'pdf', 9);
    txStepFake($this);
    $inserts = 0;
    DB::listen(function ($q) use (&$inserts, $s) {
        if (str_starts_with($q->sql, 'insert into "ai_calls"') && ++$inserts === 1) {
            DB::table('medical_documents')->where('id', $s['doc']->id)->update(['archived_at' => now()]);
        }
    });

    txStepRun($s['run']);

    expect(txStepRow($s['run'])->status)->toBe('cancelled')->and(txStepRow($s['run'])->cancel_reason)->toBe('access')
        ->and((int) txStepText($s['doc'])->pages_read)->toBe(4);
});

it('transcribes a converted HEIC photo as one page', function () {
    $s = txStepSetup('text', 'heic');
    $src = sys_get_temp_dir().'/tx-dec-src-'.bin2hex(random_bytes(4));
    file_put_contents($src, ImageFixtures::jpegWithExif(1, 64, 48));
    $dec = sys_get_temp_dir().'/tx-dec-'.bin2hex(random_bytes(4));
    file_put_contents($dec, "#!/bin/sh\ncp ".escapeshellarg($src)." \"\$2\"\n");
    chmod($dec, 0700);
    array_push($this->files, $src, $dec);
    config(['ai.heic.binary' => $dec]);
    $log = txStepFake($this);

    txStepRun($s['run']);

    expect(txStepCalls($log))->toBe(['T 1'])->and(txStepText($s['doc'])->body)->toBe('Page 1')->and(txStepRow($s['run'])->status)->toBe('text_saved');
});
