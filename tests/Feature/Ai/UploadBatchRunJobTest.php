<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Documents\AiCopyAdjuster;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\DocumentTextStore;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

function ubJobEntries(): string
{
    return json_encode([
        'schema_version' => 1, 'document_date' => '2026-03-04', 'specialty_key' => 'cardiology',
        'visits' => [], 'medications' => [], 'allergies' => [], 'vaccinations' => [],
    ]);
}

/** A fake codex that copies every image it is given to "$cap.N" and answers with empty entries. */
function ubJobCapturingFake(object $test, string $cap): string
{
    $body = 'out=; imgs=; prev=; for a in "$@"; do [ "$prev" = "-o" ] && out="$a"; [ "$prev" = "-i" ] && imgs="$a"; prev="$a"; done; cat >/dev/null; '
        .'n=0; for f in $(printf "%s" "$imgs" | tr "," " "); do n=$((n+1)); cp "$f" '.escapeshellarg($cap).'.$n; done; '
        .'printf %s '.escapeshellarg(ubJobEntries()).' > "$out"; exit 0';
    $path = sys_get_temp_dir().'/ub-fake-'.bin2hex(random_bytes(6));
    file_put_contents($path, "#!/bin/sh\n".$body."\n");
    chmod($path, 0700);
    $test->files[] = $path;
    config(['ai.codex.binary' => $path]);

    return $path;
}

function ubJobJpeg(): string
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 200, 60, 60));
    ob_start();
    imagejpeg($img, null, 95);
    imagedestroy($img);

    return (string) ob_get_clean();
}

/** @return array{user: User, doc: MedicalDocument, run: MedicalAiExtraction} */
function ubJobSetup(string $kind, string $bytes, string $mode = 'entries', ?array $adjustments = null, bool $fresh = false): array
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
    $doc = $factory->forSubject($subject)->create(['title' => 'Secret Title']);
    Storage::disk('medical')->put($doc->path, $bytes);
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id, 'mode' => $mode]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id, 'started_by' => $user->id]);
    DB::table('medical_ai_extractions')->where('id', $run->id)->update([
        'fresh_read' => $fresh,
        'adjustments' => $adjustments === null ? null : json_encode($adjustments),
    ]);

    return compact('user', 'doc', 'run');
}

function ubJobRow(MedicalAiExtraction $run): object
{
    return DB::table('medical_ai_extractions')->where('id', $run->id)->first();
}

beforeEach(function () {
    Storage::fake('medical');
    $this->files = [];
    $this->root = sys_get_temp_dir().'/ub-work-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null, 'ai.ocr.enabled' => true,
        'ai.timeouts.abort_check_seconds' => 0.2,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
    ]);
});

afterEach(function () {
    foreach ($this->files as $f) {
        @unlink($f);
    }
    foreach (glob(sys_get_temp_dir().'/ub-cap-*') ?: [] as $f) {
        @unlink($f);
    }
    foreach ([$this->root, $this->root.'-copilot', $this->root.'-cache'] as $dir) {
        if (is_dir($dir)) {
            WorkDir::removeTree($dir);
        }
    }
});

it('returns fresh_read and adjustments from claim, with defaults for old rows', function () {
    $s = ubJobSetup('image', ubJobJpeg(), 'entries', ['brightness' => 130, 'contrast' => 150, 'bw' => true], true);
    $claim = app(AiJobQuery::class)->claim($s['run']->id);
    expect($claim['fresh_read'])->toBeTrue()
        ->and($claim['adjustments'])->toEqual(['brightness' => 130, 'contrast' => 150, 'bw' => true])
        ->and($claim['mode'])->toBe('entries');

    $t = ubJobSetup('image', ubJobJpeg());
    $claim = app(AiJobQuery::class)->claim($t['run']->id);
    expect($claim['fresh_read'])->toBeFalse()->and($claim['adjustments'])->toBeNull();
});

it('sends an adjusted copy of an image and leaves the stored original untouched', function () {
    $orig = ubJobJpeg();
    $s = ubJobSetup('image', $orig, 'entries', ['brightness' => 130, 'contrast' => 150, 'bw' => true]);
    $cap = sys_get_temp_dir().'/ub-cap-'.bin2hex(random_bytes(4));
    ubJobCapturingFake($this, $cap);

    (new RunExtractionJob($s['run']->id))->handle();

    expect(ubJobRow($s['run'])->status)->toBe('ready');
    $sent = file_get_contents($cap.'.1');
    expect($sent)->not->toBe($orig);
    $img = imagecreatefromstring($sent);
    $c = imagecolorat($img, 5, 5);
    expect(abs((($c >> 16) & 255) - (($c >> 8) & 255)))->toBeLessThanOrEqual(1)
        ->and(max(imagesx($img), imagesy($img)))->toBeLessThanOrEqual(2048);
    expect(hash('sha256', Storage::disk('medical')->get($s['doc']->path)))->toBe(hash('sha256', $orig));
});

it('sends the image exactly as stored for neutral or missing adjustments', function (?array $adj) {
    $orig = ubJobJpeg();
    $s = ubJobSetup('image', $orig, 'entries', $adj);
    $cap = sys_get_temp_dir().'/ub-cap-'.bin2hex(random_bytes(4));
    ubJobCapturingFake($this, $cap);

    (new RunExtractionJob($s['run']->id))->handle();

    expect(ubJobRow($s['run'])->status)->toBe('ready')
        ->and(file_get_contents($cap.'.1'))->toBe($orig);
})->with([[null], [['brightness' => 100, 'contrast' => 100, 'bw' => false]]]);

it('adjusts every rasterised page of a pdf', function () {
    $s = ubJobSetup('pdf', MedicalFileFixtures::pdfPages(2), 'entries', ['brightness' => 50, 'contrast' => 100, 'bw' => false]);
    $cap = sys_get_temp_dir().'/ub-cap-'.bin2hex(random_bytes(4));
    ubJobCapturingFake($this, $cap);

    (new RunExtractionJob($s['run']->id))->handle();

    expect(ubJobRow($s['run'])->status)->toBe('ready');
    foreach ([1, 2] as $n) {
        $img = imagecreatefromstring(file_get_contents($cap.'.'.$n));
        // A white page darkened to mid grey.
        expect(imagecolorat($img, 2, 2) & 255)->toBeLessThan(200);
    }
});

it('adjusts the jpeg converted from a heic photo', function () {
    $s = ubJobSetup('heic', MedicalFileFixtures::heic(), 'entries', ['brightness' => 150, 'contrast' => 100, 'bw' => true]);
    // A fake heif-dec that "decodes" to a coloured JPEG.
    $src = sys_get_temp_dir().'/ub-dec-src-'.bin2hex(random_bytes(6));
    file_put_contents($src, ubJobJpeg());
    $dec = sys_get_temp_dir().'/ub-dec-'.bin2hex(random_bytes(6));
    file_put_contents($dec, "#!/bin/sh\ncp ".escapeshellarg($src)." \"\$2\"\n");
    chmod($dec, 0700);
    array_push($this->files, $src, $dec);
    config(['ai.heic.binary' => $dec]);
    $cap = sys_get_temp_dir().'/ub-cap-'.bin2hex(random_bytes(4));
    ubJobCapturingFake($this, $cap);

    (new RunExtractionJob($s['run']->id))->handle();

    expect(ubJobRow($s['run'])->status)->toBe('ready');
    $img = imagecreatefromstring(file_get_contents($cap.'.1'));
    $c = imagecolorat($img, 10, 10);
    expect(abs((($c >> 16) & 255) - ($c & 255)))->toBeLessThanOrEqual(1);
});

function ubJobStoreText(array $s): void
{
    app(DocumentTextStore::class)->saveStandalone($s['doc']->id, $s['user']->id, 'codex', 'gpt-test', 'sk', ['Old one'], 1, true);
}

function ubJobTranscribingFake(object $test): string
{
    $log = sys_get_temp_dir().'/ub-log-'.bin2hex(random_bytes(4));
    $state = sys_get_temp_dir().'/ub-state-'.bin2hex(random_bytes(4));
    $script = FakeCodex::transcribing($state, $log, 'Fresh', ['extract' => ubJobEntries()]);
    array_push($test->files, $script, $log, $state);
    config(['ai.codex.binary' => $script]);

    return $log;
}

it('reuses a complete stored text in mode full when fresh_read is false', function () {
    $s = ubJobSetup('image', ubJobJpeg(), 'full');
    ubJobStoreText($s);
    $log = ubJobTranscribingFake($this);

    (new RunExtractionJob($s['run']->id))->handle();

    $calls = is_file($log) ? array_values(array_filter(array_map('trim', file($log)))) : [];
    expect($calls)->not->toContain('T 1')
        ->and(ubJobRow($s['run'])->status)->toBe('ready');
});

it('reads the pages again on a fresh read and replaces the stored text', function () {
    $s = ubJobSetup('image', ubJobJpeg(), 'full', null, true);
    ubJobStoreText($s);
    $log = ubJobTranscribingFake($this);

    (new RunExtractionJob($s['run']->id))->handle();

    $calls = array_values(array_filter(array_map('trim', file($log))));
    $text = DB::table('medical_document_texts')->where('document_id', $s['doc']->id)->first();
    expect($calls)->toContain('T 1')
        ->and(ubJobRow($s['run'])->status)->toBe('ready')
        ->and($text->extraction_id)->toBe($s['run']->id)
        ->and($text->body)->toContain('Fresh');
});

it('ends content-free as unreadable when the adjuster fails', function () {
    // Bytes that are no image: the adjuster returns false.
    $s = ubJobSetup('image', 'Secret Title garbage bytes', 'entries', ['brightness' => 130, 'contrast' => 100, 'bw' => false]);
    $cap = sys_get_temp_dir().'/ub-cap-'.bin2hex(random_bytes(4));
    ubJobCapturingFake($this, $cap);
    Log::spy();

    (new RunExtractionJob($s['run']->id))->handle();

    $row = ubJobRow($s['run']);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('unreadable_document')
        ->and(is_file($cap.'.1'))->toBeFalse();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    expect(class_exists(AiCopyAdjuster::class))->toBeTrue();
});
