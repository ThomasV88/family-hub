<?php

use App\Models\MedicalSubject;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

/** Reports a failed statement through the application's real exception handler and returns what the log received. */
function reportedLogText(Closure $failing): string
{
    config(['logging.default' => 'scrub_probe', 'logging.channels.scrub_probe' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('scrub_probe');

    try {
        $failing();
    } catch (QueryException $e) {
        app(ExceptionHandler::class)->report($e);
    }

    $records = Log::channel('scrub_probe')->getLogger()->getHandlers()[0]->getRecords();

    return json_encode(array_map(fn ($r) => [(string) $r['message'], $r['context']], $records)) ?: '';
}

it('keeps bound values and the failing row out of the log when a medical statement fails', function () {
    $subject = MedicalSubject::factory()->create();

    $text = reportedLogText(fn () => DB::transaction(fn () => DB::table('medical_visits')->insert([
        'id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'visited_on' => '2026-01-01', 'specialty_key' => 'cardiology',
        'diagnosis' => 'SECRET-DIAGNOSIS-XYZ', 'notes' => 'SECRET-NOTES-XYZ', 'source' => 'bogus', 'created_at' => now(), 'updated_at' => now(),
    ])));

    expect($text)->toContain('medical table')
        ->and($text)->not->toContain('SECRET-DIAGNOSIS-XYZ')
        ->and($text)->not->toContain('SECRET-NOTES-XYZ')
        ->and($text)->not->toContain('insert into')
        ->and($text)->not->toContain('Failing row');
});

it('leaves failures on non-medical tables to the default report', function () {
    $text = reportedLogText(fn () => DB::transaction(fn () => DB::table('users')->insert(['name' => 'x', 'email' => null, 'password' => 'x'])));

    expect($text)->not->toContain('medical table');
});
