<?php

namespace App\Wishlist\Occasions;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ModulePermission;
use App\Models\User;
use App\Models\WishOccasion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves birthdays, name days, Christmas and custom occasions to concrete dates in the family time zone.
 * Annual dates go through AnnualDate::next (Feb 29 is observed on Feb 28 in non-leap years).
 */
final class OccasionCalendar
{
    private const ORDER = [
        OccasionOccurrence::BIRTHDAY => 0,
        OccasionOccurrence::NAMEDAY => 1,
        OccasionOccurrence::CHRISTMAS => 2,
        OccasionOccurrence::CUSTOM => 3,
    ];

    /** @return list<OccasionOccurrence> sorted by date, then type, then owner and id */
    public function upcoming(CarbonImmutable $today, int $horizonDays): array
    {
        $today = $this->day($today);
        $out = [];

        foreach ($this->birthdayUsers() as $user) {
            $out[] = $this->birthday($user, AnnualDate::next((int) $user->birth_date->month, (int) $user->birth_date->day, $today), $today);
        }
        foreach (WishOccasion::query()->with('owner:id,name')->orderBy('id')->get() as $occasion) {
            $date = $this->occasionDate($occasion, $today);
            if ($date !== null) {
                $out[] = $this->occasion($occasion, $date, $today);
            }
        }
        $christmas = $this->christmas($today);
        $out[] = $this->christmasOccurrence($christmas, $today);

        $out = array_values(array_filter($out, fn (OccasionOccurrence $o) => $o->daysAway >= 0 && $o->daysAway <= $horizonDays));
        usort($out, fn (OccasionOccurrence $a, OccasionOccurrence $b) => [$a->date->format('Y-m-d'), self::ORDER[$a->type], $a->ownerId ?? 0, $a->occasionId ?? 0]
            <=> [$b->date->format('Y-m-d'), self::ORDER[$b->type], $b->ownerId ?? 0, $b->occasionId ?? 0]);

        return $out;
    }

    /** The occurrence of a subject on exactly $date ('YYYY-MM-DD'), or null if it no longer falls on that day. */
    public function occurrenceOn(string $subjectType, int $subjectId, string $date): ?OccasionOccurrence
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $target = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $this->zone());
        $today = $this->day(CarbonImmutable::now());

        if ($subjectType === OccasionOccurrence::SUBJECT_CHRISTMAS) {
            $christmas = $this->christmasIn($subjectId, $today);

            return $christmas->equalTo($target) ? $this->christmasOccurrence($christmas, $today) : null;
        }

        if ($subjectType === OccasionOccurrence::SUBJECT_BIRTHDAY) {
            $user = $this->birthdayUsers($subjectId)->first();
            if ($user === null) {
                return null;
            }
            $next = AnnualDate::next((int) $user->birth_date->month, (int) $user->birth_date->day, $target);

            return $next->equalTo($target) ? $this->birthday($user, $target, $today) : null;
        }

        if ($subjectType === OccasionOccurrence::SUBJECT_OCCASION) {
            $occasion = WishOccasion::query()->with('owner:id,name')->find($subjectId);
            if ($occasion === null) {
                return null;
            }
            $actual = $occasion->year !== null
                ? $this->exact($occasion, $occasion->year)
                : AnnualDate::next($occasion->month, $occasion->day, $target);

            return $actual !== null && $actual->equalTo($target) ? $this->occasion($occasion, $target, $today) : null;
        }

        return null;
    }

    private function birthday(User $user, CarbonImmutable $date, CarbonImmutable $today): OccasionOccurrence
    {
        return new OccasionOccurrence(
            OccasionOccurrence::BIRTHDAY, $user->id, $user->name, null, null, $date,
            AnnualDate::daysUntil($date, $today), OccasionOccurrence::SUBJECT_BIRTHDAY, $user->id,
        );
    }

    private function occasion(WishOccasion $occasion, CarbonImmutable $date, CarbonImmutable $today): OccasionOccurrence
    {
        $nameday = $occasion->kind === WishOccasion::NAMEDAY;

        return new OccasionOccurrence(
            $nameday ? OccasionOccurrence::NAMEDAY : OccasionOccurrence::CUSTOM,
            $occasion->owner_id,
            $occasion->owner?->name,
            $nameday ? null : $occasion->title,
            $occasion->id,
            $date,
            AnnualDate::daysUntil($date, $today),
            OccasionOccurrence::SUBJECT_OCCASION,
            $occasion->id,
        );
    }

    private function christmasOccurrence(CarbonImmutable $date, CarbonImmutable $today): OccasionOccurrence
    {
        return new OccasionOccurrence(
            OccasionOccurrence::CHRISTMAS, null, null, null, null, $date,
            AnnualDate::daysUntil($date, $today), OccasionOccurrence::SUBJECT_CHRISTMAS, $date->year,
        );
    }

    private function occasionDate(WishOccasion $occasion, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($occasion->year === null) {
            return AnnualDate::next($occasion->month, $occasion->day, $today);
        }
        $date = $this->exact($occasion, $occasion->year);

        return $date !== null && $date->greaterThanOrEqualTo($today) ? $date : null;
    }

    private function exact(WishOccasion $occasion, int $year): ?CarbonImmutable
    {
        if (! checkdate($occasion->month, $occasion->day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $occasion->month, $occasion->day, 0, 0, 0, $this->zone());
    }

    private function christmas(CarbonImmutable $today): CarbonImmutable
    {
        return AnnualDate::next((int) config('wishlist.christmas.month'), (int) config('wishlist.christmas.day'), $today);
    }

    private function christmasIn(int $year, CarbonImmutable $today): CarbonImmutable
    {
        return $today->setDate($year, (int) config('wishlist.christmas.month'), (int) config('wishlist.christmas.day'))->startOfDay();
    }

    /** Members with wishlist view access and a birth date (optionally one id). */
    private function birthdayUsers(?int $id = null): Collection
    {
        return User::query()
            ->whereNotNull('birth_date')
            ->when($id !== null, fn ($q) => $q->where('users.id', $id))
            ->whereIn('users.id', ModulePermission::query()
                ->select('user_id')
                ->where('module', Module::Wishlist->value)
                ->where('level', '>=', AccessLevel::View->value))
            ->orderBy('id')
            ->get(['id', 'name', 'birth_date']);
    }

    private function day(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone($this->zone())->startOfDay();
    }

    private function zone(): string
    {
        return (string) config('family.timezone');
    }
}
