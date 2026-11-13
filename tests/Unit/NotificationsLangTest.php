<?php

use Tests\TestCase;

uses(TestCase::class);

function notificationLines(string $locale): array
{
    return require base_path("lang/{$locale}/notifications.php");
}

function notificationPlaceholders(string $value): array
{
    preg_match_all('/:(\w+)/', $value, $m);
    $found = array_unique($m[1]);
    sort($found);

    return array_values($found);
}

it('has identical keys in every locale', function (string $locale) {
    $en = notificationLines('en');
    $other = notificationLines($locale);

    expect(array_keys($other))->toEqualCanonicalizing(array_keys($en));
    expect(array_keys($en))->toEqualCanonicalizing(
        ['reminder_title', 'reminder_greeting', 'reminder_body', 'reminder_action', 'test_title']
    );
    foreach ($other as $key => $value) {
        expect(trim($value))->not->toBe('', $key);
    }
})->with(['sk', 'pl']);

it('keeps every placeholder of en', function (string $locale) {
    $en = notificationLines('en');
    $other = notificationLines($locale);

    foreach ($en as $key => $value) {
        expect(notificationPlaceholders($other[$key]))->toBe(notificationPlaceholders($value), $key);
    }
})->with(['sk', 'pl']);

it('has no exclamation marks and no notes placeholder', function (string $locale) {
    foreach (notificationLines($locale) as $key => $value) {
        expect(str_contains($value, '!'))->toBeFalse($key);
        expect(str_contains($value, ':notes'))->toBeFalse($key);
    }
})->with(['en', 'sk', 'pl']);

it('renders the reminder title per locale', function (string $locale, string $expected) {
    expect(trans('notifications.reminder_title', ['title' => 'X'], $locale))->toBe($expected);
})->with([
    ['en', 'Task due: X'],
    ['sk', 'Termín úlohy: X'],
    ['pl', 'Termin zadania: X'],
]);

it('renders the reminder body with title and date', function (string $locale, string $expected) {
    expect(trans('notifications.reminder_body', ['title' => 'Milk', 'date' => '5 Mar'], $locale))->toBe($expected);
})->with([
    ['en', '"Milk" is due 5 Mar.'],
    ['sk', 'Úloha „Milk“ je splatná 5 Mar.'],
    ['pl', 'Zadanie „Milk” ma termin 5 Mar.'],
]);
