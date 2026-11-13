<?php

namespace App\Finance\Import;

use DateTimeImmutable;
use InvalidArgumentException;

final class DateParser
{
    /** Strict parse with an allowlisted format; returns Y-m-d or null. */
    public static function parse(string $value, string $format): ?string
    {
        if (! in_array($format, config('finance.import.date_formats'), true)) {
            throw new InvalidArgumentException('Date format is not allowlisted.');
        }
        $value = trim(preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?$/', '', trim($value)) ?? '');
        if ($value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        // 'Y' accepts short years (01/02/26 -> year 0026): treat anything outside a sane range as a mismatch.
        if ((int) $d->format('Y') < 1900) {
            return null;
        }

        return $d->format('Y-m-d');
    }
}
