<?php

namespace App\Medical\Ai;

/** The one rule for a model identifier passed to an AI CLI (never starts with a dash, no spaces or slashes). */
final class ModelId
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,59}$/D';

    public static function isValid(string $model): bool
    {
        return preg_match(self::PATTERN, $model) === 1;
    }
}
