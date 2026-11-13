<?php

namespace App\Recurrence;

use InvalidArgumentException;

/**
 * A user-facing repeat choice. Immutable and canonical: equal schedules always compare equal,
 * so RuleBuilder::fromRrule() is the exact inverse of RuleBuilder::toRrule().
 */
final readonly class RecurrencePattern
{
    public const KINDS = ['daily', 'weekly', 'monthly_day', 'monthly_last', 'custom'];

    public const UNITS = ['day', 'week', 'month'];

    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    private const KEYS = ['kind', 'interval', 'unit', 'weekdays', 'monthDay'];

    /**
     * @param  list<string>  $weekdays
     */
    private function __construct(
        public string $kind,
        public int $interval,
        public ?string $unit,
        public array $weekdays,
        public ?int $monthDay,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $extra = array_diff(array_keys($data), self::KEYS);
        if ($extra !== []) {
            throw new InvalidArgumentException('Unknown recurrence keys: '.implode(',', array_map('strval', $extra)));
        }

        $kind = $data['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown recurrence kind.');
        }

        $interval = $data['interval'] ?? 1;
        if (! is_int($interval) || $interval < 1 || $interval > 99) {
            throw new InvalidArgumentException('Interval must be an integer from 1 to 99.');
        }

        $unit = $data['unit'] ?? null;
        $weekdays = $data['weekdays'] ?? null;
        $monthDay = $data['monthDay'] ?? null;

        if ($kind !== 'custom' && $interval !== 1) {
            throw new InvalidArgumentException('Only a custom repeat may use an interval other than 1.');
        }
        if ($unit !== null && $kind !== 'custom') {
            throw new InvalidArgumentException('Unit is only valid for a custom repeat.');
        }
        if ($weekdays !== null && $kind !== 'weekly') {
            throw new InvalidArgumentException('Weekdays are only valid for a weekly repeat.');
        }

        if ($kind === 'custom') {
            if (! is_string($unit) || ! in_array($unit, self::UNITS, true)) {
                throw new InvalidArgumentException('A custom repeat needs a unit of day, week or month.');
            }
            if ($unit === 'month') {
                $monthDay = self::validMonthDay($monthDay);
            } elseif ($monthDay !== null) {
                throw new InvalidArgumentException('monthDay is only valid for monthly repeats.');
            }
            if ($interval === 1 && $unit === 'day') {
                return new self('daily', 1, null, [], null);
            }
            if ($interval === 1 && $unit === 'month') {
                return new self('monthly_day', 1, null, [], $monthDay);
            }

            return new self('custom', $interval, $unit, [], $unit === 'month' ? $monthDay : null);
        }

        if ($kind === 'monthly_day') {
            return new self($kind, 1, null, [], self::validMonthDay($monthDay));
        }
        if ($monthDay !== null) {
            throw new InvalidArgumentException('monthDay is only valid for monthly_day and custom months.');
        }

        if ($kind === 'weekly') {
            if (! is_array($weekdays) || $weekdays === [] || ! array_is_list($weekdays)) {
                throw new InvalidArgumentException('A weekly repeat needs at least one weekday.');
            }
            foreach ($weekdays as $day) {
                if (! is_string($day) || ! in_array($day, self::WEEKDAYS, true)) {
                    throw new InvalidArgumentException('Invalid weekday.');
                }
            }
            $ordered = array_values(array_filter(self::WEEKDAYS, fn (string $d) => in_array($d, $weekdays, true)));

            return new self('weekly', 1, null, $ordered, null);
        }

        return new self($kind, 1, null, [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind, 'interval' => $this->interval];
        if ($this->unit !== null) {
            $out['unit'] = $this->unit;
        }
        if ($this->kind === 'weekly') {
            $out['weekdays'] = $this->weekdays;
        }
        if ($this->monthDay !== null) {
            $out['monthDay'] = $this->monthDay;
        }

        return $out;
    }

    private static function validMonthDay(mixed $monthDay): int
    {
        if (! is_int($monthDay) || $monthDay < 1 || $monthDay > 31) {
            throw new InvalidArgumentException('monthDay must be an integer from 1 to 31.');
        }

        return $monthDay;
    }
}
