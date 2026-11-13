<?php

namespace App\Wishlist\Occasions;

/** Static name-day lookup over the vendored sk/pl calendars (database/data/namedays). */
final class NameDayCalendar
{
    private const LOCALES = ['sk' => 'sk_SK', 'pl' => 'pl_PL'];

    /** @var array<string, list<array{name: string, folded: string, month: int, day: int}>> */
    private static array $entries = [];

    private static ?\Transliterator $fold = null;

    /** @return list<string> */
    public static function calendars(): array
    {
        return array_keys(self::LOCALES);
    }

    /** @return list<array{name: string, month: int, day: int}> */
    public static function search(string $calendar, string $query, int $limit = 8): array
    {
        $entries = self::load($calendar);
        $q = self::fold(trim($query));
        if (mb_strlen($q) < 2 || $limit < 1) {
            return [];
        }

        $prefix = [];
        $inner = [];
        foreach ($entries as $e) {
            if (str_starts_with($e['folded'], $q)) {
                $prefix[] = $e;
            } elseif (str_contains($e['folded'], $q)) {
                $inner[] = $e;
            }
        }

        $collator = new \Collator(self::LOCALES[$calendar]);
        $sort = static function (array &$list) use ($collator): void {
            usort($list, static fn (array $a, array $b): int => $collator->compare($a['name'], $b['name'])
                ?: [$a['month'], $a['day']] <=> [$b['month'], $b['day']]);
        };
        $sort($prefix);
        $sort($inner);

        return array_map(
            static fn (array $e): array => ['name' => $e['name'], 'month' => $e['month'], 'day' => $e['day']],
            array_slice(array_merge($prefix, $inner), 0, $limit),
        );
    }

    public static function has(string $calendar, string $name, int $month, int $day): bool
    {
        foreach (self::load($calendar) as $e) {
            if ($e['month'] === $month && $e['day'] === $day && $e['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $value): string
    {
        self::$fold ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        return self::$fold->transliterate($value);
    }

    /** @return list<array{name: string, folded: string, month: int, day: int}> */
    private static function load(string $calendar): array
    {
        if (! isset(self::LOCALES[$calendar])) {
            throw new \InvalidArgumentException("Unknown name-day calendar {$calendar}");
        }
        if (! isset(self::$entries[$calendar])) {
            $data = json_decode(
                (string) file_get_contents(dirname(__DIR__, 3)."/database/data/namedays/{$calendar}.json"),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $list = [];
            foreach ($data['days'] as $key => $names) {
                [$m, $d] = array_map('intval', explode('-', $key));
                foreach ($names as $name) {
                    $list[] = ['name' => $name, 'folded' => self::fold($name), 'month' => $m, 'day' => $d];
                }
            }
            self::$entries[$calendar] = $list;
        }

        return self::$entries[$calendar];
    }
}
