<?php

use App\Wishlist\Fetch\MetaImageExtractor;

const META_PAGE = 'https://shop.example/products/lamp?id=3';

function metaFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../../fixtures/fetch/'.$name);
}

it('orders candidates by source priority', function () {
    $html = <<<'HTML'
    <html><head>
    <script type="application/ld+json">{"@type":"Product","image":"https://s.example/ld.jpg"}</script>
    <link rel="image_src" href="https://s.example/link.jpg">
    <meta name="twitter:image:src" content="https://s.example/tw-src.jpg">
    <meta name="twitter:image" content="https://s.example/tw.jpg">
    <meta property="og:image" content="https://s.example/og.jpg">
    <meta property="og:image:secure_url" content="https://s.example/og-secure.jpg">
    </head></html>
    HTML;
    expect(MetaImageExtractor::candidates($html, META_PAGE))->toBe([
        'https://s.example/og-secure.jpg', 'https://s.example/og.jpg', 'https://s.example/tw.jpg',
        'https://s.example/tw-src.jpg', 'https://s.example/link.jpg',
    ]);
});

it('ignores attribute order, quote style and case', function () {
    $html = "<META CONTENT='https://s.example/a.jpg' PROPERTY='OG:Image'><meta content=\"https://s.example/b.jpg\" name=\"Twitter:Image\"><LINK HREF=https://s.example/c.jpg REL=\"Image_Src\">";
    expect(MetaImageExtractor::candidates($html, META_PAGE))->toBe(['https://s.example/a.jpg', 'https://s.example/b.jpg', 'https://s.example/c.jpg']);
});

it('reads JSON-LD Product images in graph, list, ImageObject and typed-array shapes', function () {
    expect(MetaImageExtractor::candidates(metaFixture('jsonld-graph.html'), META_PAGE))->toBe([
        'https://cdn.example/lamp1.jpg', 'https://cdn.example/lamp2.jpg', 'https://cdn.example/lamp3.jpg',
    ]);
});

it('resolves relative and protocol-relative candidates against the page URL', function () {
    $html = '<meta property="og:image" content="/img/a.jpg"><meta name="twitter:image" content="//cdn.example/b.jpg"><meta name="twitter:image:src" content="pic.jpg">';
    expect(MetaImageExtractor::candidates($html, META_PAGE))->toBe([
        'https://shop.example/img/a.jpg', 'https://cdn.example/b.jpg', 'https://shop.example/products/pic.jpg',
    ]);
});

it('drops non-http candidates, empties and duplicates, and caps at five', function () {
    $metas = '<meta property="og:image" content="javascript:alert(1)"><meta property="og:image" content="data:image/png;base64,AAAA">'
        .'<meta property="og:image" content=""><meta property="og:image" content="ftp://x.example/a.jpg">';
    for ($i = 0; $i < 8; $i++) {
        $metas .= '<meta property="og:image" content="https://s.example/'.($i % 7).'.jpg">';
    }
    $out = MetaImageExtractor::candidates($metas, META_PAGE);
    expect($out)->toHaveCount(5)->and($out)->toBe(array_values(array_unique($out)))
        ->and($out[0])->toBe('https://s.example/0.jpg');
});

it('converts a windows-1250 page before parsing', function () {
    expect(MetaImageExtractor::candidates(metaFixture('cp1250.html'), META_PAGE))->toBe(['https://shop.example/img/č.jpg']);
});

it('truncates oversize input instead of rejecting it', function () {
    $html = '<meta property="og:image" content="https://s.example/early.jpg">'.str_repeat('<p>x</p>', 100000)
        .'<meta property="og:image" content="https://s.example/late.jpg">';
    expect(strlen($html))->toBeGreaterThan(524288)
        ->and(MetaImageExtractor::candidates($html, META_PAGE))->toBe(['https://s.example/early.jpg']);
});

it('emits no warnings on malformed HTML', function () {
    $errors = [];
    set_error_handler(function ($no, $str) use (&$errors) {
        $errors[] = $str;

        return true;
    });
    $out = MetaImageExtractor::candidates('<html><head><meta property="og:image" content="https://s.example/a.jpg"><div><p></span></div><<>>&bogus; <script type="application/ld+json">[[[[', META_PAGE);
    restore_error_handler();
    expect($errors)->toBe([])->and($out)->toBe(['https://s.example/a.jpg'])
        ->and(MetaImageExtractor::candidates('', META_PAGE))->toBe([]);
});

it('survives deeply nested JSON-LD', function () {
    $json = str_repeat('[', 40).'{"@type":"Product","image":"https://s.example/deep.jpg"}'.str_repeat(']', 40);
    expect(MetaImageExtractor::candidates('<script type="application/ld+json">'.$json.'</script>', META_PAGE))->toBe([]);
    $shallow = str_repeat('[', 10).'{"@type":"Product","image":"https://s.example/ok.jpg"}'.str_repeat(']', 10);
    expect(MetaImageExtractor::candidates('<script type="application/ld+json">'.$shallow.'</script>', META_PAGE))->toBe(['https://s.example/ok.jpg']);
});
