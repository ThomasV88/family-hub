<?php

use App\Medical\Ai\Eval\EntryScorer;

function ocrScoreDoc(array $meds = [['Paralen', '500 mg']]): array
{
    return [
        'schema_version' => 1,
        'document_date' => '2026-03-12',
        'specialty_key' => 'general_practice',
        'visits' => [['visited_on' => '2026-03-12', 'doctor' => 'MUDr. Žilinská (demo)', 'diagnosis' => 'Angína']],
        'medications' => array_map(fn ($m) => ['name' => $m[0], 'dose' => $m[1]], $meds),
        'allergies' => [['substance' => 'Penicilín']],
        'vaccinations' => [['vaccine' => 'Tetanus', 'given_on' => '2024-05-01']],
    ];
}

it('scores identical lists as perfect', function () {
    $s = EntryScorer::score(ocrScoreDoc(), ocrScoreDoc());
    expect($s['f1'])->toBe(1.0)->and($s['fp'])->toBe(0)->and($s['fn'])->toBe(0);
});

it('lowers recall but not precision for a missing medication', function () {
    $s = EntryScorer::score(ocrScoreDoc([['Paralen', '500 mg'], ['Ibalgin', '400 mg']]), ocrScoreDoc());
    expect($s['precision'])->toBe(1.0)->and($s['recall'])->toBeLessThan(1.0)->and($s['fn'])->toBe(1);
});

it('matches a dose after normalisation', function () {
    expect(EntryScorer::score(ocrScoreDoc([['Paralen', '500 mg']]), ocrScoreDoc([['PARALEN', '500mg']]))['f1'])->toBe(1.0);
});

it('counts a wrong entry as a false positive and a false negative', function () {
    $s = EntryScorer::score(ocrScoreDoc([['Paralen', '500 mg']]), ocrScoreDoc([['Paralen', '100 mg']]));
    expect($s['fp'])->toBe(1)->and($s['fn'])->toBe(1);
});

it('treats two empty documents as perfect', function () {
    expect(EntryScorer::score([], [])['f1'])->toBe(1.0);
});
