<?php

use App\Medical\Documents\Text\SearchTerms;
use App\Medical\Documents\Text\Snippets;
use Tests\TestCase;

uses(TestCase::class);

function snipFlat(array $snippet): string
{
    return implode('', array_map(fn (array $s) => $s['text'], $snippet));
}

it('keeps the original spelling in the hit segment', function () {
    $r = Snippets::build("Pacient: Ján Novák\nDiagnóza: žltačka", SearchTerms::parse('zltacka'));

    expect($r['snippets'])->toHaveCount(1)
        ->and(array_values(array_filter($r['snippets'][0], fn ($s) => $s['hit'])))->toBe([['text' => 'žltačka', 'hit' => true]])
        ->and($r['page'])->toBe(1);
});

it('returns the page of the first hit', function () {
    $body = "one\fsecond page\fthe ZxHit here";
    $r = Snippets::build($body, SearchTerms::parse('zxhit'));

    expect($r['page'])->toBe(3);
});

it('never lets a window cross a page break', function () {
    $body = str_repeat('alfa ', 30)."bravo\f"."charlie delta\f".str_repeat('echo ', 30);
    foreach (['bravo', 'charlie', 'delta'] as $q) {
        $r = Snippets::build($body, SearchTerms::parse($q));
        foreach ($r['snippets'] as $snippet) {
            expect(snipFlat($snippet))->not->toContain("\f");
        }
    }
    $r = Snippets::build($body, SearchTerms::parse('charlie'));
    expect(snipFlat($r['snippets'][0]))->toBe('charlie delta')->and($r['page'])->toBe(2);
});

it('merges two close hits into one snippet with two hit segments', function () {
    $r = Snippets::build('Take paracetamol and 20 characters later penicilin again', SearchTerms::parse('paracetamol penicilin'));

    expect($r['snippets'])->toHaveCount(1)
        ->and(count(array_filter($r['snippets'][0], fn ($s) => $s['hit'])))->toBe(2);
});

it('gives at most two snippets for far apart hits', function () {
    $body = 'needle '.str_repeat('filler ', 60).'needle '.str_repeat('filler ', 60).'needle';
    $r = Snippets::build($body, SearchTerms::parse('needle'));

    expect($r['snippets'])->toHaveCount(2);
});

it('returns nothing for a body without a hit', function () {
    expect(Snippets::build('nothing relevant here', SearchTerms::parse('zxhit')))->toBe(['snippets' => [], 'page' => null]);
    expect(Snippets::build('', SearchTerms::parse('zxhit')))->toBe(['snippets' => [], 'page' => null]);
});

it('aligns on tokens even when folding changes the length', function () {
    $body = 'Straße Maß und zażółć gęślą jaźń Łukasz ende';
    $r = Snippets::build($body, SearchTerms::parse('lukasz'));
    $hit = array_values(array_filter($r['snippets'][0], fn ($s) => $s['hit']));

    expect($hit)->toBe([['text' => 'Łukasz', 'hit' => true]])
        ->and(snipFlat($r['snippets'][0]))->toBe($body);

    $r = Snippets::build('Maßnahme Strasse', SearchTerms::parse('massnahme'));
    expect($r['snippets'][0][0])->toBe(['text' => 'Maßnahme', 'hit' => true])
        ->and(snipFlat($r['snippets'][0]))->toBe('Maßnahme Strasse');
});

it('cuts windows at word boundaries and keeps segments plain text', function () {
    $body = str_repeat('wordywordy ', 40).'<b>needle</b>'.str_repeat(' wordywordy', 40);
    $r = Snippets::build($body, SearchTerms::parse('needle'));
    $text = snipFlat($r['snippets'][0]);

    expect(mb_strlen($text))->toBeLessThan(200)
        ->and($text)->toStartWith('wordywordy')->and($text)->toEndWith('wordywordy')
        ->and($text)->toContain('<b>');
    foreach ($r['snippets'][0] as $s) {
        expect(array_keys($s))->toBe(['text', 'hit']);
    }
});

it('handles a large body quickly', function () {
    $body = str_repeat('Pacient Novák má žltačku a užíva liek. ', 5300).' zxcanary';
    expect(mb_strlen($body))->toBeGreaterThan(200000 - 1000);

    $t = microtime(true);
    $r = Snippets::build($body, SearchTerms::parse('zxcanary'));
    $none = Snippets::build($body, SearchTerms::parse('neexistuje'));
    $ms = (microtime(true) - $t) * 1000;

    expect($r['snippets'])->toHaveCount(1)->and($none['page'])->toBeNull()->and($ms)->toBeLessThan(200);
});
