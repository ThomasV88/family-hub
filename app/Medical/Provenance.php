<?php

namespace App\Medical;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of "unverified": source is 'ai' and nobody has confirmed it (verified_at is null). Every
 * record table carries source and verified_at; exports exclude unverified rows by default and label them when included.
 */
final class Provenance
{
    public static function isUnverified(string $source, ?string $verifiedAt): bool
    {
        return $source === 'ai' && $verifiedAt === null;
    }

    /**
     * @param  object  $row  a model or row with source and verified_at
     * @return array{verification: string, source: string, verified_at: ?string}
     */
    public static function present(object $row): array
    {
        $verifiedAt = $row->verified_at ?? null;
        $iso = $verifiedAt instanceof CarbonInterface ? $verifiedAt->toIso8601String() : ($verifiedAt === null ? null : (string) $verifiedAt);
        $source = (string) ($row->source ?? 'manual');

        return [
            'verification' => self::isUnverified($source, $iso) ? 'unverified' : 'verified',
            'source' => $source,
            'verified_at' => $iso,
        ];
    }

    /** Keeps only rows that are NOT unverified. */
    public static function excludeUnverified(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('source', 'manual')->orWhereNotNull('verified_at'));
    }

    /** Keeps only unverified rows. */
    public static function onlyUnverified(Builder $query): Builder
    {
        return $query->where('source', 'ai')->whereNull('verified_at');
    }
}
