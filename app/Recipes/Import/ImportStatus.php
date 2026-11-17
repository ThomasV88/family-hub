<?php

namespace App\Recipes\Import;

/** Lifecycle of a recipe import. The strings are the stored values. */
enum ImportStatus: string
{
    case Fetching = 'fetching';
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Used = 'used';

    /** @return list<string> */
    public static function active(): array
    {
        return [self::Fetching->value, self::Queued->value, self::Running->value];
    }

    /** @return list<string> shown in the member's draft list */
    public static function listed(): array
    {
        return [...self::active(), self::Ready->value, self::Failed->value];
    }
}
