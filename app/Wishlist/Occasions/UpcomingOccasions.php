<?php

namespace App\Wishlist\Occasions;

use Carbon\CarbonImmutable;

/**
 * The next few occasions for the wishlist strip and the Overview card. Built only from the calendar (no
 * reservation data), so the value is identical for every viewer. Only the next-occurrence date is exposed, never a birth year.
 */
final class UpcomingOccasions
{
    public function __construct(private readonly OccasionCalendar $calendar) {}

    /** @return list<array{key: string, type: string, owner: array{id: int, name: string|null}|null, title: string|null, date: string, days_away: int}> */
    public function next(CarbonImmutable $today, int $days, int $limit): array
    {
        return array_map(fn (OccasionOccurrence $o) => [
            'key' => $o->subjectType.':'.$o->subjectId.':'.$o->date->format('Y-m-d'),
            'type' => $o->type,
            'owner' => $o->ownerId === null ? null : ['id' => $o->ownerId, 'name' => $o->ownerName],
            'title' => $o->title,
            'date' => $o->date->format('Y-m-d'),
            'days_away' => $o->daysAway,
        ], array_slice($this->calendar->upcoming($today, $days), 0, $limit));
    }

    /** The strip window in the family time zone (config wishlist.strip_days, at most 3 entries). */
    public function strip(): array
    {
        $today = CarbonImmutable::now((string) config('family.timezone'))->startOfDay();

        return $this->next($today, (int) config('wishlist.strip_days'), 3);
    }
}
