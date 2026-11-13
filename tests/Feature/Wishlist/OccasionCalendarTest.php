<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\WishOccasion;
use App\Wishlist\Occasions\OccasionCalendar;
use App\Wishlist\Occasions\OccasionOccurrence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

function calendar(): OccasionCalendar
{
    return app(OccasionCalendar::class);
}

function at(string $datetime): CarbonImmutable
{
    Carbon::setTestNow(CarbonImmutable::parse($datetime, 'Europe/Bratislava'));

    return CarbonImmutable::now('Europe/Bratislava');
}

function member(array $attrs = []): User
{
    return User::factory()->withPreset(Preset::Parent)->create($attrs);
}

afterEach(fn () => Carbon::setTestNow());

it('yields a birthday with days away in the family time zone', function () {
    $today = at('2027-03-01 08:30');
    $user = member(['birth_date' => '1990-03-15']);

    $birthdays = array_values(array_filter(calendar()->upcoming($today, 60), fn ($o) => $o->type === 'birthday'));

    expect($birthdays)->toHaveCount(1)
        ->and($birthdays[0]->date->format('Y-m-d'))->toBe('2027-03-15')
        ->and($birthdays[0]->daysAway)->toBe(14)
        ->and($birthdays[0]->ownerId)->toBe($user->id)
        ->and($birthdays[0]->subjectType)->toBe('user_birthday')
        ->and($birthdays[0]->subjectId)->toBe($user->id);
});

it('observes a Feb 29 birthday on Feb 28 in non-leap years', function () {
    member(['birth_date' => '2000-02-29']);

    $early = calendar()->upcoming(at('2027-02-01 09:00'), 400);
    $late = calendar()->upcoming(at('2027-03-01 09:00'), 400);

    $first = fn (array $list) => array_values(array_filter($list, fn ($o) => $o->type === 'birthday'))[0]->date->format('Y-m-d');
    expect($first($early))->toBe('2027-02-28')->and($first($late))->toBe('2028-02-29');
});

it('resolves name days, custom yearly and one-off occasions and Christmas', function () {
    $today = at('2027-03-01 08:30');
    $owner = member();
    WishOccasion::factory()->nameday('sk', 'Ľubomír', 11, 9)->create(['owner_id' => $owner->id]);
    WishOccasion::factory()->create(['title' => 'Yearly', 'month' => 4, 'day' => 2]);
    WishOccasion::factory()->create(['title' => 'One-off', 'month' => 5, 'day' => 6, 'year' => 2027]);
    WishOccasion::factory()->create(['title' => 'Past', 'month' => 1, 'day' => 6, 'year' => 2027]);

    $byType = collect(calendar()->upcoming($today, 400))->groupBy('type');

    expect($byType['nameday'][0]->date->format('Y-m-d'))->toBe('2027-11-09')
        ->and($byType['nameday'][0]->ownerName)->toBe($owner->name)
        ->and($byType['custom']->map(fn ($o) => $o->title.':'.$o->date->format('Y-m-d'))->all())->toBe(['Yearly:2027-04-02', 'One-off:2027-05-06'])
        ->and($byType['christmas'][0]->date->format('Y-m-d'))->toBe('2027-12-24')
        ->and($byType['christmas'][0]->subjectId)->toBe(2027);

    $after = collect(calendar()->upcoming(at('2027-12-25 08:00'), 400))->firstWhere('type', 'christmas');
    expect($after->date->format('Y-m-d'))->toBe('2028-12-24');
});

it('drops a one-off occasion once its date has passed but keeps it on the day', function () {
    WishOccasion::factory()->create(['title' => 'One-off', 'month' => 5, 'day' => 6, 'year' => 2027]);

    $onDay = collect(calendar()->upcoming(at('2027-05-06 23:30'), 30))->firstWhere('type', 'custom');
    $after = collect(calendar()->upcoming(at('2027-05-07 00:10'), 30))->firstWhere('type', 'custom');

    expect($onDay->daysAway)->toBe(0)->and($after)->toBeNull();
});

it('respects the horizon, wishlist access and sort order', function () {
    $today = at('2027-03-01 08:30');
    member(['birth_date' => '1990-03-05']);
    member(['birth_date' => '1990-06-20']);
    User::factory()->create(['birth_date' => '1990-03-03']); // no wishlist access
    WishOccasion::factory()->create(['title' => 'Same day', 'month' => 3, 'day' => 5]);

    $list = calendar()->upcoming($today, 60);

    expect(collect($list)->pluck('type')->all())->toBe(['birthday', 'custom'])
        ->and(collect($list)->pluck('daysAway')->all())->toBe([4, 4]);
});

it('re-checks an occurrence on a date', function () {
    at('2027-03-01 08:30');
    $user = member(['birth_date' => '1990-03-15']);
    $occasion = WishOccasion::factory()->create(['title' => 'Trip', 'month' => 4, 'day' => 2]);

    expect(calendar()->occurrenceOn('user_birthday', $user->id, '2027-03-15'))->toBeInstanceOf(OccasionOccurrence::class)
        ->and(calendar()->occurrenceOn('wish_occasion', $occasion->id, '2027-04-02')->title)->toBe('Trip')
        ->and(calendar()->occurrenceOn('christmas', 2027, '2027-12-24')->type)->toBe('christmas')
        ->and(calendar()->occurrenceOn('christmas', 2027, '2027-12-23'))->toBeNull();

    $user->forceFill(['birth_date' => '1990-03-16'])->save();
    expect(calendar()->occurrenceOn('user_birthday', $user->id, '2027-03-15'))->toBeNull();

    $occasion->delete();
    expect(calendar()->occurrenceOn('wish_occasion', $occasion->id, '2027-04-02'))->toBeNull()
        ->and(calendar()->occurrenceOn('wish_occasion', 1, 'not-a-date'))->toBeNull();
});

it('counts whole days across both DST changes', function (string $now, string $birth, int $expected) {
    $today = at($now);
    member(['birth_date' => $birth]);

    $o = collect(calendar()->upcoming($today, 40))->firstWhere('type', 'birthday');

    expect($o->daysAway)->toBe($expected);
})->with([
    'spring forward' => ['2027-03-26 23:30', '1990-03-30', 4],
    'spring across' => ['2027-03-27 00:10', '1990-03-29', 2],
    'autumn back' => ['2027-10-29 23:30', '1990-11-02', 4],
    'autumn across' => ['2027-10-30 00:10', '1990-10-31', 1],
]);

it('rejects invalid occasion rows at the database', function (array $attrs) {
    expect(fn () => WishOccasion::factory()->create($attrs))->toThrow(QueryException::class);
})->with([
    'nameday without calendar' => [['kind' => 'nameday', 'owner_id' => 1, 'name' => 'X', 'calendar' => null]],
    'month 13' => [['month' => 13]],
    'day 0' => [['day' => 0]],
    'custom without title' => [['title' => null]],
]);

it('allows one name day per member only', function () {
    $owner = member();
    WishOccasion::factory()->nameday()->create(['owner_id' => $owner->id]);

    expect(fn () => WishOccasion::factory()->nameday('pl', 'Anna', 7, 26)->create(['owner_id' => $owner->id]))
        ->toThrow(QueryException::class);
});
