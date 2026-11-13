<?php

use App\Finance\TextNormalizer;

it('normalizes text across diacritics', function (string $in, string $out) {
    expect(TextNormalizer::normalize($in))->toBe($out);
})->with([
    ['Żabka Łódź', 'zabka lodz'], ['ZABKA LODZ', 'zabka lodz'],
    ['Škôlka  -- Zaplatené!', 'skolka zaplatene'], ['Straße', 'strasse'], ['Đurđa', 'durda'], ['', ''],
]);
