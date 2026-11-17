<?php

use App\Medical\Documents\Text\TextNormalizer;
use Tests\TestCase;

uses(TestCase::class);

it('composes decomposed characters to NFC', function () {
    expect(TextNormalizer::page("z\u{030C}"))->toBe('ž');
});

it('turns CRLF and CR into LF', function () {
    expect(TextNormalizer::page("a\r\nb\rc"))->toBe("a\nb\nc");
});

it('removes direction marks and control characters but keeps TAB and LF', function () {
    $in = "a\u{202E}b\u{200F}c\u{0007}d\u{000C}e\tf\ng\u{0085}h\u{2066}i\u{061C}";

    expect(TextNormalizer::page($in))->toBe("abcde\tf\nghi");
});

it('scrubs invalid UTF-8', function () {
    $out = TextNormalizer::page("ok\xC3\x28 end");

    expect(mb_check_encoding($out, 'UTF-8'))->toBeTrue()->and($out)->toStartWith('ok')->and($out)->toEndWith('end');
});

it('strips trailing blanks per line', function () {
    expect(TextNormalizer::page("a  \nb\t\n  c   "))->toBe("a\nb\n  c");
});

it('cuts a long page to the page limit on a code point boundary', function () {
    $out = TextNormalizer::page(str_repeat('ž', 13000));

    expect(mb_strlen($out))->toBe(12000)->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue();
});

it('joins pages with exactly one form feed between them', function () {
    expect(TextNormalizer::body(['a', 'b', 'c']))->toBe("a\x0cb\x0cc")
        ->and(substr_count(TextNormalizer::body(["a\x0cb", 'c', '']), "\x0c"))->toBe(2);
});

it('drops whole trailing pages beyond the total limit', function () {
    config(['ai.ocr.max_total_chars' => 25, 'ai.ocr.max_page_chars' => 12000]);
    $body = TextNormalizer::body([str_repeat('a', 10), str_repeat('b', 10), str_repeat('c', 10)]);

    expect(mb_strlen($body))->toBe(21)->and(substr_count($body, "\x0c"))->toBe(1)->and($body)->not->toContain('c');
});

it('cuts the first page when not even it fits', function () {
    config(['ai.ocr.max_total_chars' => 5]);

    expect(TextNormalizer::body([str_repeat('a', 10), 'b']))->toBe('aaaaa');
});

it('handles no pages', function () {
    expect(TextNormalizer::body([]))->toBe('');
});
