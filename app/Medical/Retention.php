<?php

namespace App\Medical;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The delete window of the health module (owner decision B). The server decides, never the client: a person or record
 * created within the last `medical.delete_window_hours` may be deleted for good, anything older can only be archived.
 * The age is now minus created_at. A window of 0 hours always archives.
 */
final class Retention
{
    public static function hours(): int
    {
        return max(0, min(8760, (int) config('medical.delete_window_hours', 72)));
    }

    /** Whether a row created at $createdAt may still be deleted permanently. */
    public static function canDeletePermanently(CarbonInterface|string|null $createdAt): bool
    {
        if ($createdAt === null) {
            return false;
        }
        $hours = self::hours();
        if ($hours === 0) {
            return false;
        }
        $created = $createdAt instanceof CarbonInterface ? $createdAt : Carbon::parse($createdAt);

        return $created->greaterThan(now()->subHours($hours));
    }
}
