<?php

use App\Shopping\NameKey;

it('ignores case, accents and surplus whitespace', function () {
    expect(NameKey::from('  Mliečko  Rajo '))->toBe(NameKey::from('mliecko rajo'));
});

it('keeps punctuation', function () {
    expect(NameKey::from("Ben & Jerry's"))->toBe("ben & jerry's");
});

it('keeps an empty string empty', function () {
    expect(NameKey::from(''))->toBe('');
});

it('does not collapse names without a transliterable letter', function () {
    expect(NameKey::from('🍎'))->not->toBe('')
        ->and(NameKey::from('🍎'))->not->toBe(NameKey::from('🥛'))
        ->and(NameKey::from('🥛 '))->toBe(NameKey::from('🥛'));
});
