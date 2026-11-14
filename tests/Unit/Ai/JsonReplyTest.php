<?php

use App\Medical\Ai\JsonReply;
use Tests\TestCase;

uses(TestCase::class);

function replyFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'jr');
    file_put_contents($path, $content);

    return $path;
}

it('accepts a plain object and tolerates exactly one code fence', function (string $content, ?string $expected) {
    $path = replyFile($content);
    expect(JsonReply::decode($path))->toBe($expected);
    unlink($path);
})->with([
    'plain' => ["  {\"a\":1}\n", '{"a":1}'],
    'json fence' => ["```json\n{\"a\":1}\n```\n", '{"a":1}'],
    'bare fence' => ["```\n{\"a\":1}\n```", '{"a":1}'],
    'two blocks' => ["```json\n{\"a\":1}\n```\n```json\n{\"b\":2}\n```", null],
    'prose before' => ["Here you go:\n{\"a\":1}", null],
    'array root' => ['[{"a":1}]', null],
    'empty' => ['', null],
    'trailing comma' => ['{"a":1,}', null],
    'prose after' => ["{\"a\":1}\nDone.", null],
]);

it('rejects a missing file and a file over the cap', function () {
    expect(JsonReply::decode(sys_get_temp_dir().'/jr-missing-'.bin2hex(random_bytes(4))))->toBeNull();
    $path = replyFile('{"a":"'.str_repeat('x', 300 * 1024).'"}');
    expect(JsonReply::decode($path))->toBeNull();
    unlink($path);
});
