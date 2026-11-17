<?php

use App\Medical\Ai\Eval\Corpus;
use App\Medical\Ai\Eval\CorpusRejected;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Models\AiBulkRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFileFixtures;

const OCR_EVAL_CANARY = 'ZXQCANARYWORD';

/** A tiny page-like JPEG or PNG written to $path. */
function ocrEvalImage(string $path, string $type = 'jpg', int $w = 360, int $h = 260): void
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($img, 255, 255, 255));
    $ink = (int) imagecolorallocate($img, 0, 0, 0);
    for ($y = 20; $y < $h - 20; $y += 14) {
        imagefilledrectangle($img, 20, $y, $w - 20, $y + 4, $ink);
    }
    $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 85);
    imagedestroy($img);
}

/**
 * Builds a synthetic corpus folder with a valid manifest. Returns [dir, manifest].
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function ocrEvalCorpus(string $root, bool $pdf = false): array
{
    $dir = $root.'/eval';
    mkdir($dir, 0700, true);
    $items = [];
    $add = function (string $id, string $doc, string $cond, string $kind, string $file) use (&$items, $dir) {
        $items[] = ['id' => $id, 'doc' => $doc, 'condition' => $cond, 'kind' => $kind, 'file' => $file, 'sha256' => hash_file('sha256', $dir.'/'.$file)];
    };
    ocrEvalImage($dir.'/probe.4000.png', 'png', 300, 300);
    $add('probe-png', 'probe', 'clean', 'probe', 'probe.4000.png');
    ocrEvalImage($dir.'/probe.4000.jpg', 'jpg', 300, 300);
    $add('probe-jpg', 'probe', 'clean', 'probe', 'probe.4000.jpg');
    foreach (['d1-sk-discharge' => 'd1', 'd2-pl-prescription' => 'd2', 'd3-en-lab' => 'd3'] as $doc => $short) {
        ocrEvalImage($dir.'/'.$doc.'.clean.jpg');
        $add($short.'-clean', $doc, 'clean', 'image', $doc.'.clean.jpg');
    }
    if ($pdf) {
        file_put_contents($dir.'/d1-sk-discharge.pdf', MedicalFileFixtures::pdfPages(1));
        $add('d1-pdf', 'd1-sk-discharge', 'pdf', 'pdf', 'd1-sk-discharge.pdf');
    }
    $manifest = ['synthetic' => true, 'generator' => 'test', 'items' => $items];
    file_put_contents($dir.'/MANIFEST.json', json_encode($manifest));

    return [$dir, $manifest];
}

function ocrEvalReplies(): array
{
    $page = ['page' => 1, 'legible' => true, 'text' => 'Pacient Žitňanský (demo) '.OCR_EVAL_CANARY.' paracetamol 500 mg'];
    $entries = ['document_date' => '2026-03-09', 'specialty_key' => 'internal_medicine', 'visits' => [],
        'medications' => [['name' => 'Paralen', 'dose' => '500 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => []];

    return [
        'transcription.v1.schema.json' => json_encode(['schema_version' => 1, 'language' => 'sk', 'pages' => [$page]]),
        'extraction.v1.schema.json' => json_encode(['schema_version' => 1] + $entries),
        'combined.schema.json' => json_encode(['schema_version' => 1, 'language' => 'sk', 'pages' => [$page]] + $entries),
    ];
}

/** @param array<string, mixed> $options */
function ocrEvalRun(array $options): array
{
    $code = Artisan::call('ai:ocr-eval', $options);

    return [$code, Artisan::output()];
}

function ocrEvalRowCounts(): array
{
    $out = [];
    foreach (Schema::getTableListing() as $t) {
        $out[$t] = DB::table($t)->count();
    }

    return $out;
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ocr-eval-'.strtolower((string) Str::ulid());
    mkdir($this->root, 0700, true);
    $this->home = $this->root.'-home';
    mkdir($this->home, 0700, true);
    $this->log = $this->root.'-calls.log';
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->home, 'ai.codex.model' => null,
        'ai.timeouts.process' => 30, 'ai.timeouts.idle' => 30,
    ]);
    $this->scripts = [];
    $this->fake = function (array $options = [], ?array $replies = null) {
        $path = FakeCodex::bySchema($replies ?? ocrEvalReplies(), ['log' => $this->log] + $options);
        $this->scripts[] = $path;
        config(['ai.codex.binary' => $path]);
    };
    $this->calls = fn () => is_file($this->log) ? count(file($this->log)) : 0;
    $this->args = fn (array $extra = []) => ['--dir' => $this->root.'/eval', '--confirm-synthetic' => true, '--pause' => 0] + $extra;
    $this->report = fn () => json_decode((string) file_get_contents(glob($this->root.'/eval/report-*.json')[0]), true);
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    @unlink($this->log);
    exec('rm -rf '.escapeshellarg($this->root).' '.escapeshellarg($this->home));
});

it('refuses outside staging and testing', function (string $env) {
    [$dir] = ocrEvalCorpus($this->root);
    ($this->fake)();
    $this->app->detectEnvironment(fn () => $env);

    [$code, $out] = ocrEvalRun(($this->args)());
    expect($code)->toBe(1)->and($out)->toContain('refused: environment '.$env)->and(($this->calls)())->toBe(0);
})->with(['production', 'local']);

it('refuses without --confirm-synthetic', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();

    [$code, $out] = ocrEvalRun(['--dir' => $this->root.'/eval', '--pause' => 0]);
    expect($code)->toBe(1)->and($out)->toContain('--confirm-synthetic is required')->and(($this->calls)())->toBe(0);
});

it('refuses a folder that is not a proven synthetic corpus', function (string $case, string $reason) {
    [$dir, $manifest] = ocrEvalCorpus($this->root);
    ($this->fake)();
    $m = $manifest;
    switch ($case) {
        case 'not_synthetic':
            $m['synthetic'] = false;
            file_put_contents($dir.'/MANIFEST.json', json_encode($m));
            break;
        case 'sha256_mismatch':
            file_put_contents($dir.'/d1-sk-discharge.clean.jpg', 'tampered');
            break;
        case 'symlink':
            unlink($dir.'/d2-pl-prescription.clean.jpg');
            symlink($dir.'/d1-sk-discharge.clean.jpg', $dir.'/d2-pl-prescription.clean.jpg');
            break;
        case 'unlisted_file':
            file_put_contents($dir.'/extra.jpg', 'x');
            break;
        case 'no_manifest':
            unlink($dir.'/MANIFEST.json');
            break;
        case 'bad_extension':
            $m['items'][0]['file'] = 'probe.4000.png.exe';
            file_put_contents($dir.'/probe.4000.png.exe', 'x');
            file_put_contents($dir.'/MANIFEST.json', json_encode($m));
            break;
        case 'outside_eval_root':
            $other = $this->root.'/other';
            mkdir($other);
            copy($dir.'/MANIFEST.json', $other.'/MANIFEST.json');
            [$code, $out] = ocrEvalRun(['--dir' => $other, '--confirm-synthetic' => true, '--pause' => 0]);
            expect($code)->toBe(1)->and($out)->toContain('corpus '.$reason)->and(($this->calls)())->toBe(0);

            return;
    }

    [$code, $out] = ocrEvalRun(($this->args)());
    expect($code)->toBe(1)->and($out)->toContain('refused: corpus '.$reason)->and(($this->calls)())->toBe(0);
})->with([
    ['not_synthetic', 'not_synthetic'],
    ['sha256_mismatch', 'sha256_mismatch'],
    ['symlink', 'symlink'],
    ['unlisted_file', 'unlisted_file'],
    ['no_manifest', 'no_manifest'],
    ['bad_extension', 'bad_extension'],
    ['outside_eval_root', 'outside_eval_root'],
]);

it('refuses a folder inside the medical disk', function () {
    $medical = (string) config('filesystems.disks.medical.root').'/';
    @mkdir($medical, 0700, true);
    $inside = rtrim($medical, '/').'/ocr-eval-guard-'.bin2hex(random_bytes(4));
    mkdir($inside, 0700);
    file_put_contents($inside.'/MANIFEST.json', json_encode(['synthetic' => true, 'generator' => 't', 'items' => []]));
    try {
        // The folder is under the allowed root here, so only the medical disk rule can refuse it.
        expect(fn () => Corpus::load($inside, rtrim($medical, '/')))
            ->toThrow(CorpusRejected::class, 'inside_private_documents');
    } finally {
        @unlink($inside.'/MANIFEST.json');
        @rmdir($inside);
    }
});

it('refuses while a job sits on the ai queue, a bulk run is open or an extraction is queued', function (string $case) {
    ocrEvalCorpus($this->root);
    ($this->fake)();
    if ($case === 'job') {
        DB::table('jobs')->insert(['queue' => 'ai', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    } elseif ($case === 'bulk_job') {
        DB::table('jobs')->insert(['queue' => 'ai-bulk', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    } else {
        AiBulkRun::factory()->create(['status' => 'active']);
    }

    [$code, $out] = ocrEvalRun(($this->args)());
    expect($code)->toBe(1)->and($out)->toContain('AI worker is not free')->and(($this->calls)())->toBe(0);
})->with(['job', 'bulk_job', 'bulk_run']);

it('refuses when the Codex login is not usable', function () {
    ocrEvalCorpus($this->root);
    $path = FakeCodex::loginStatus(1, 'Not logged in');
    $this->scripts[] = $path;
    config(['ai.codex.binary' => $path]);

    [$code, $out] = ocrEvalRun(($this->args)());
    expect($code)->toBe(1)->and($out)->toContain('login is not usable');
});

it('refuses forbidden AI environment variables', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();
    putenv('OPENAI_API_KEY=sk-test');
    try {
        [$code, $out] = ocrEvalRun(($this->args)());
    } finally {
        putenv('OPENAI_API_KEY');
    }
    expect($code)->toBe(1)->and($out)->toContain('forbidden AI environment variables')->and($out)->not->toContain('sk-test');
});

it('runs stages A and B one call at a time and reports metrics, tokens and decisions without any model text', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)(['usage' => ['input_tokens' => 1500, 'cached_input_tokens' => 0, 'output_tokens' => 320, 'reasoning_output_tokens' => 10]]);
    $before = ocrEvalRowCounts();

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['A', 'B']]));
    expect($code)->toBe(0)->and(($this->calls)())->toBe(10); // 4 probe calls + 3 documents x 2 repeats

    $r = ($this->report)();
    expect($r['rows'])->toHaveCount(10)
        ->and($r['rows'][0])->toHaveKeys(['stage', 'item', 'arm', 'ok', 'ms', 'pre_ms', 'peak_mb', 'tokens', 'cer', 'wer', 'diacritics', 'drop_rate', 'numeric', 'recall'])
        ->and($r['rows'][0]['tokens'])->toBe(['input' => 1500, 'cached' => 0, 'output' => 320, 'reasoning' => 10])
        ->and($r['resolution_probe']['arms'])->toHaveKeys(['p4000png', 'p4000jpg', 'p2048png', 'p2048jpg'])
        ->and($r['aggregates'])->toHaveKey('B:A0')
        ->and($r['aggregates']['B:A0']['tokens_input'])->toEqual(1500)
        ->and($r['noise_sd'])->toEqual(0)
        ->and($r['decisions'])->toHaveKeys(['preprocess', 'pdf_scale', 'pipeline', 'store_text', 'seconds_per_page']);

    // Nothing the model read leaves the process, and the database is untouched.
    $raw = (string) file_get_contents(glob($this->root.'/eval/report-*.json')[0]);
    expect($raw)->not->toContain(OCR_EVAL_CANARY)->not->toContain('Žitňanský')
        ->and($out)->not->toContain(OCR_EVAL_CANARY)->not->toContain('Žitňanský')
        ->and($out)->toContain('stage=A')->and($out)->toContain('projected_total=')
        ->and(DB::table('ai_calls')->count())->toBe(0)
        ->and(ocrEvalRowCounts())->toBe($before);
    expect(file_get_contents($this->root.'/eval/state.json'))->not->toContain(OCR_EVAL_CANARY);
});

it('stops after exactly --max-calls calls with a partial report', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['A'], '--max-calls' => 3]));
    expect($code)->toBe(0)->and(($this->calls)())->toBe(3);
    $r = ($this->report)();
    expect($r['stopped_reason'])->toBe('max_calls')->and($r['rows'])->toHaveCount(3)->and($r['calls']['this_run'])->toBe(3);
});

it('stops at the first usage-limit message and keeps what it has', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)(['quota_after' => 1]);

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['A']]));
    expect($code)->toBe(0)->and(($this->calls)())->toBe(2); // one answer, then the limit: no third call
    $r = ($this->report)();
    expect($r['stopped_reason'])->toBe('quota')->and($r['rows'])->toHaveCount(1)->and($out)->toContain('stopped_reason=quota');
});

it('stops when the projected total exceeds the cap after stage B', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['A', 'B', 'C'], '--max-calls' => 12]));
    expect($code)->toBe(0)->and(($this->calls)())->toBe(10)->and($out)->toContain('projected_total=16 max_calls=12');
    expect(($this->report)()['stopped_reason'])->toBe('projected_over_cap');
});

it('resumes from state.json and does not repeat finished calls', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();
    ocrEvalRun(($this->args)(['--stage' => ['A', 'B']]));
    expect(($this->calls)())->toBe(10);
    foreach (glob($this->root.'/eval/report-*.json') as $f) {
        unlink($f);
    }

    [$code] = ocrEvalRun(($this->args)(['--stage' => ['C']]));
    expect($code)->toBe(0)->and(($this->calls)())->toBe(16); // 3 documents x clean x A0 and A5
    $r = ($this->report)();
    expect($r['rows'])->toHaveCount(16)->and($r['calls']['total'])->toBe(16)
        ->and(array_values(array_unique(array_column($r['rows'], 'stage'))))->toBe(['A', 'B', 'C'])
        ->and($r['aggregates'])->toHaveKeys(['C:A0', 'C:A5'])
        ->and($r['verdicts']['A5'])->not->toBeNull();

    foreach (glob($this->root.'/eval/report-*.json') as $f) {
        unlink($f);
    }
    ocrEvalRun(($this->args)(['--stage' => ['C']]));
    expect(($this->calls)())->toBe(16); // nothing left to do
});

it('compares the pipelines and the PDF scales in stages E and F', function () {
    ocrEvalCorpus($this->root, pdf: true);
    ($this->fake)();

    [$code] = ocrEvalRun(($this->args)(['--stage' => ['C', 'E', 'F']]));
    expect($code)->toBe(0);
    $r = ($this->report)();
    $arms = array_count_values(array_map(fn ($x) => $x['stage'].':'.$x['arm'], $r['rows']));
    expect($arms['E:P0'])->toBe(3)->and($arms['E:P1'])->toBe(3)->and($arms['E:P2'])->toBe(3)
        ->and($arms['F:PDF1800'])->toBe(1)->and($arms['F:PDF2048'])->toBe(1)
        ->and($r['pipelines']['P0']['f1'])->toBeFloat()
        ->and($r['decisions']['pipeline'])->toBeIn(['two_call', 'combined'])
        ->and($r['decisions']['pdf_scale'])->toBeIn([1800, 2048]);
    expect((string) file_get_contents(glob($this->root.'/eval/report-*.json')[0]))->not->toContain(OCR_EVAL_CANARY);
});

it('skips the Copilot check with a reason when Copilot is not enabled', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();

    [$code] = ocrEvalRun(($this->args)(['--stage' => ['G']]));
    expect($code)->toBe(0)->and(($this->report)()['copilot'])->toBe(['skipped' => 'copilot_not_enabled'])->and(($this->calls)())->toBe(0);
});

it('removes the transcripts and the state with --stage=cleanup', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();
    ocrEvalRun(($this->args)(['--stage' => ['A', 'B', 'C']]));
    expect(is_file($this->root.'/eval/state.json'))->toBeTrue()->and(is_dir($this->root.'/eval/work'))->toBeTrue();
    $calls = ($this->calls)();

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['cleanup']]));
    expect($code)->toBe(0)->and(is_file($this->root.'/eval/state.json'))->toBeFalse()->and(is_dir($this->root.'/eval/work'))->toBeFalse()
        ->and(is_file($this->root.'/eval/MANIFEST.json'))->toBeTrue()->and(($this->calls)())->toBe($calls);
});

it('rejects unknown stages and out-of-range options', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();

    expect(ocrEvalRun(($this->args)(['--stage' => ['Z']]))[0])->toBe(1)
        ->and(ocrEvalRun(($this->args)(['--max-calls' => 0]))[0])->toBe(1)
        ->and(ocrEvalRun(($this->args)(['--stage' => ['cleanup', 'A']]))[0])->toBe(1)
        ->and(ocrEvalRun(($this->args)(['--out' => '/tmp/elsewhere.json', '--stage' => ['G']]))[0])->toBe(1)
        ->and(($this->calls)())->toBe(0);
});

it('prints only the exception class when something unexpected fails', function () {
    ocrEvalCorpus($this->root);
    ($this->fake)();
    $this->app->bind(AiAgentSettingsQuery::class, fn () => throw new RuntimeException(OCR_EVAL_CANARY.' secret message'));

    [$code, $out] = ocrEvalRun(($this->args)(['--stage' => ['A']]));
    expect($code)->toBe(1)->and($out)->toContain('failed: RuntimeException')->and($out)->not->toContain(OCR_EVAL_CANARY);
});
