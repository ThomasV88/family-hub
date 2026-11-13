<?php

use App\Wishlist\Occasions\NameDayCalendar;

it('lists the calendars', function () {
    expect(NameDayCalendar::calendars())->toBe(['sk', 'pl']);
});

it('finds names without diacritics or case', function () {
    $names = array_column(NameDayCalendar::search('sk', 'lubo', 8), 'name');
    expect($names)->toContain('Ľubomír');

    $pl = NameDayCalendar::search('pl', 'Łukasz');
    expect($pl)->not->toBeEmpty()
        ->and($pl[0])->toHaveKeys(['name', 'month', 'day'])
        ->and(NameDayCalendar::search('pl', 'lukasz'))->toBe($pl);
});

it('limits results and ranks prefix matches first', function () {
    $r = NameDayCalendar::search('pl', 'an', 5);
    expect($r)->toHaveCount(5);
    foreach ($r as $row) {
        expect(mb_strtolower($row['name']))->toStartWith('an');
    }
});

it('rejects unknown calendars and short queries', function () {
    expect(fn () => NameDayCalendar::search('xx', 'abc'))->toThrow(InvalidArgumentException::class)
        ->and(NameDayCalendar::search('sk', ''))->toBe([])
        ->and(NameDayCalendar::search('sk', 'a'))->toBe([]);
});

it('checks an exact name on a day', function () {
    $hit = NameDayCalendar::search('sk', 'Ľubomír', 1)[0];
    expect(NameDayCalendar::has('sk', 'Ľubomír', $hit['month'], $hit['day']))->toBeTrue()
        ->and(NameDayCalendar::has('sk', 'Ľubomír', 1, 1))->toBeFalse()
        ->and(NameDayCalendar::has('sk', 'Lubomir', $hit['month'], $hit['day']))->toBeFalse();
});
