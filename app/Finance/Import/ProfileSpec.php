<?php

namespace App\Finance\Import;

use InvalidArgumentException;

/** Allowlisted generic CSV profile settings (no bank-named profiles). */
final class ProfileSpec
{
    public const ROLES = ['date', 'amount', 'income', 'expense', 'indicator', 'description', 'counterparty', 'reference', 'currency'];

    public const MODES = ['single', 'split', 'indicator'];

    /**
     * @param  array<string, array{name: ?string, index: ?int}>  $columns
     * @param  list<string>  $indicatorExpense
     * @param  list<string>  $indicatorIncome
     */
    public function __construct(
        public readonly string $delimiter,
        public readonly string $encoding,
        public readonly string $decimalSeparator,
        public readonly ?string $groupingSeparator,
        public readonly string $dateFormat,
        public readonly int $headerRow,
        public readonly string $amountMode,
        public readonly array $columns,
        public readonly array $indicatorExpense = [],
        public readonly array $indicatorIncome = [],
    ) {}

    public static function fromArray(array $a): self
    {
        $delimiter = $a['delimiter'] ?? null;
        if (! is_string($delimiter) || ! in_array($delimiter, config('finance.import.delimiters'), true)) {
            throw new InvalidArgumentException('Delimiter is not allowlisted.');
        }
        $encoding = is_string($a['encoding'] ?? null) ? strtoupper($a['encoding']) : '';
        if (! in_array($encoding, config('finance.import.encodings'), true)) {
            throw new InvalidArgumentException('Encoding is not allowlisted.');
        }
        $decimal = $a['decimalSeparator'] ?? null;
        if (! in_array($decimal, [',', '.'], true)) {
            throw new InvalidArgumentException('Decimal separator is not allowlisted.');
        }
        $grouping = $a['groupingSeparator'] ?? null;
        if ($grouping === '') {
            $grouping = null;
        }
        if ($grouping !== null && (! in_array($grouping, [' ', '.', ','], true) || $grouping === $decimal)) {
            throw new InvalidArgumentException('Grouping separator is not allowed.');
        }
        $dateFormat = $a['dateFormat'] ?? null;
        if (! is_string($dateFormat) || ! in_array($dateFormat, config('finance.import.date_formats'), true)) {
            throw new InvalidArgumentException('Date format is not allowlisted.');
        }
        $headerRow = $a['headerRow'] ?? null;
        if (! is_int($headerRow) || $headerRow < 0 || $headerRow > 1000) {
            throw new InvalidArgumentException('Header row is invalid.');
        }
        $mode = $a['amountMode'] ?? null;
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Amount mode is not allowlisted.');
        }

        $columns = [];
        $rawColumns = $a['columns'] ?? [];
        if (! is_array($rawColumns)) {
            throw new InvalidArgumentException('Columns are invalid.');
        }
        foreach ($rawColumns as $role => $col) {
            if (! in_array($role, self::ROLES, true) || ! is_array($col)) {
                throw new InvalidArgumentException('Unknown column role.');
            }
            $name = $col['name'] ?? null;
            $index = $col['index'] ?? null;
            if ($name !== null && (! is_string($name) || mb_strlen($name) > 200)) {
                throw new InvalidArgumentException('Column name is invalid.');
            }
            if ($index !== null && (! is_int($index) || $index < 0 || $index > 200)) {
                throw new InvalidArgumentException('Column index is invalid.');
            }
            if ($name === null && $index === null) {
                continue;
            }
            $columns[$role] = ['name' => $name, 'index' => $index];
        }

        return new self(
            $delimiter, $encoding, $decimal, $grouping, $dateFormat, $headerRow, $mode, $columns,
            self::tokens($a['indicatorExpense'] ?? []), self::tokens($a['indicatorIncome'] ?? []),
        );
    }

    /** @return list<string> */
    private static function tokens(mixed $v): array
    {
        if (! is_array($v) || count($v) > 20) {
            throw new InvalidArgumentException('Indicator list is invalid.');
        }
        $out = [];
        foreach ($v as $t) {
            if (! is_string($t) || mb_strlen($t) > 20) {
                throw new InvalidArgumentException('Indicator value is invalid.');
            }
            $out[] = $t;
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'encoding' => $this->encoding,
            'decimalSeparator' => $this->decimalSeparator,
            'groupingSeparator' => $this->groupingSeparator,
            'dateFormat' => $this->dateFormat,
            'headerRow' => $this->headerRow,
            'amountMode' => $this->amountMode,
            'columns' => $this->columns,
            'indicatorExpense' => $this->indicatorExpense,
            'indicatorIncome' => $this->indicatorIncome,
        ];
    }
}
