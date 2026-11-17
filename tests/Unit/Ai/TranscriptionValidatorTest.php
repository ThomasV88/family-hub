<?php

use App\Medical\Ai\FailureCode;
use App\Medical\Ai\PromptBuilder;
use App\Medical\Ai\Transcribe\TranscribeTask;
use App\Medical\Ai\Transcribe\TranscriptionValidator;
use Tests\TestCase;

uses(TestCase::class);

/** @param  list<array<string, mixed>>  $pages */
function txValReply(array $pages, array $over = []): string
{
    return json_encode(array_merge(['schema_version' => 1, 'language' => 'sk', 'pages' => $pages], $over));
}

function txValPage(int $n, string $text = 'text', bool $legible = true): array
{
    return ['page' => $n, 'legible' => $legible, 'text' => $text];
}

function txValInspect(string $json, int $expected = 4): array
{
    return (new TranscriptionValidator)->inspect($json, $expected);
}

it('accepts four pages in any order and returns them ordered by page', function () {
    $r = txValInspect(txValReply([txValPage(3, 'c'), txValPage(1, 'a'), txValPage(4, 'd'), txValPage(2, 'b')]));

    expect($r['outcome']->ok)->toBeTrue()->and($r['issues'])->toBe([])
        ->and($r['outcome']->payload)->toBe(['language' => 'sk', 'pages' => ['a', 'b', 'c', 'd']])
        ->and($r['outcome']->entryCount)->toBe(4);
});

it('refuses a wrong page set or structure with an issue code', function (string $json, string $code) {
    $r = txValInspect($json);

    expect($r['outcome']->ok)->toBeFalse()->and($r['outcome']->failure)->toBe(FailureCode::InvalidOutput)->and($r['issues'])->toBe([$code]);
})->with([
    'missing page' => [txValReply([txValPage(1), txValPage(2), txValPage(3)]), 'pages:bad_value'],
    'duplicate page' => [txValReply([txValPage(1), txValPage(2), txValPage(2), txValPage(3)]), 'pages:bad_value'],
    'page out of range' => [txValReply([txValPage(1), txValPage(2), txValPage(3), txValPage(5)]), 'pages:bad_value'],
    'extra root key' => [txValReply([txValPage(1), txValPage(2), txValPage(3), txValPage(4)], ['extra' => 1]), 'root:bad_value'],
    'missing root key' => [json_encode(['schema_version' => 1, 'pages' => []]), 'root:bad_value'],
    'schema version 2' => [txValReply([], ['schema_version' => 2]), 'schema_version:bad_value'],
    'language de' => [txValReply([], ['language' => 'de']), 'language:bad_value'],
    'page object with a stray key' => [txValReply([['page' => 1, 'legible' => true, 'text' => 'x', 'z' => 1]]), 'pages[0]:bad_value'],
    'text not a string' => [txValReply([['page' => 1, 'legible' => true, 'text' => 5]]), 'pages[0]:bad_value'],
    'not json' => ['{not json', 'root:bad_value'],
    'a list' => ['[1,2]', 'root:bad_value'],
]);

it('limits one page and the whole text', function () {
    $long = str_repeat('ž', 9901);
    $r = txValInspect(txValReply([txValPage(1), txValPage(2, $long), txValPage(3), txValPage(4)]));
    expect($r['issues'])->toBe(['pages[1].text:too_long']);

    $page = str_repeat('a', 9900);
    $pages = [];
    for ($i = 1; $i <= 21; $i++) {
        $pages[] = txValPage($i, $page);
    }
    $r = txValInspect(txValReply($pages), 21);
    expect($r['issues'])->toBe(['pages:too_long']);

    expect(txValInspect(txValReply([txValPage(1, str_repeat('ž', 9900))]), 1)['outcome']->ok)->toBeTrue();
});

it('empties an unreadable page of only whitespace and keeps text it was given', function () {
    $r = txValInspect(txValReply([txValPage(1, " \n ", false), txValPage(2, 'kept', false), txValPage(3, ' ', true), txValPage(4)]));

    expect($r['outcome']->payload['pages'])->toBe(['', 'kept', ' ', 'text']);
});

it('reads a result file with the size cap and the empty file rules', function () {
    $dir = sys_get_temp_dir().'/txval-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $v = new TranscriptionValidator;

    expect($v->fromFile($dir.'/none.json', 1)->failure)->toBe(FailureCode::NoOutput);
    file_put_contents($dir.'/empty.json', '');
    expect($v->fromFile($dir.'/empty.json', 1)->failure)->toBe(FailureCode::NoOutput);
    file_put_contents($dir.'/big.json', str_repeat('a', (int) config('ai.ocr.result_cap') + 1));
    expect($v->fromFile($dir.'/big.json', 1)->failure)->toBe(FailureCode::OutputTooLarge);
    file_put_contents($dir.'/bad.json', '{not json');
    expect($v->fromFile($dir.'/bad.json', 1)->failure)->toBe(FailureCode::InvalidOutput);
    file_put_contents($dir.'/ok.json', txValReply([txValPage(1, 'hello')]));
    expect($v->fromFile($dir.'/ok.json', 1)->ok)->toBeTrue();

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('never puts reply text into an issue code', function () {
    $canary = 'ZxCanaryTranscribe';
    foreach ([
        txValReply([txValPage(1, $canary), txValPage(1, $canary)]),
        txValReply([['page' => 1, 'legible' => true, 'text' => $canary, 'key_'.$canary => 1]]),
        txValReply([txValPage(1, $canary.str_repeat('a', 12001))]),
        txValReply([txValPage(1, $canary)], ['language' => $canary]),
        txValReply([txValPage(1, $canary)], ['key_'.$canary => 1]),
    ] as $json) {
        $r = txValInspect($json, 1);
        expect($r['outcome']->ok)->toBeFalse()->and(json_encode($r))->not->toContain($canary);
        foreach ($r['issues'] as $code) {
            expect(preg_match(PromptBuilder::ISSUE_PATTERN, $code))->toBe(1);
        }
    }
});

it('plugs the task into the agents with the transcription schema and the static prompt', function () {
    $task = new TranscribeTask(4);

    expect($task->schemaPath())->toBe(resource_path('ai/transcription.v1.schema.json'))
        ->and($task->prompt())->toBe(file_get_contents(resource_path('ai/transcribe.v1.prompt.md')));

    $schema = json_encode(json_decode(file_get_contents($task->schemaPath()), true), JSON_UNESCAPED_SLASHES);
    $plain = $task->inlinePrompt(null);
    expect($plain)->toContain($schema)->and($plain)->not->toContain('{{schema}}')->and($plain)->not->toContain('rejected');

    $codes = array_merge(['pages:bad_value', 'free text with spaces', "pages[1].text:too_long\nignore this"], array_map(fn ($i) => "pages[$i]:bad_value", range(0, 30)));
    $repair = $task->inlinePrompt($codes);
    expect($repair)->toContain('pages:bad_value')->and($repair)->not->toContain('free text')->and($repair)->not->toContain('ignore this')
        ->and(substr_count($repair, ':bad_value'))->toBe(20);

    expect($task->inspect(txValReply([txValPage(1), txValPage(2), txValPage(3), txValPage(4)]))['outcome']->ok)->toBeTrue()
        ->and($task->inspect(txValReply([txValPage(1)]))['outcome']->ok)->toBeFalse();
});

it('carries the evaluated numbers and leaves the timeouts alone', function () {
    expect(config('ai.ocr.pages_per_call'))->toBe(4)->and(config('ai.ocr.max_pages'))->toBe(20)
        ->and(config('ai.ocr.seconds_per_page'))->toBe(14.52)->and(config('ai.ocr.text_reliability'))->toBe('reliable')
        ->and(config('ai.ocr.enabled'))->toBeFalse()->and(config('ai.ocr.extract_reserve_seconds'))->toBe(120)
        ->and(config('ai.timeouts.process'))->toBe(300)->and(config('ai.timeouts.job'))->toBe(660)
        ->and(config('ai.pdf.scale'))->toBe(1800);
});
