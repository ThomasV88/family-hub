<?php

namespace App\Medical\Ai;

/** Stable per-entry keys (visit-0, medication-1, ...) over a validated payload. */
final class ProposalEntries
{
    private const LISTS = ['visit' => 'visits', 'medication' => 'medications', 'allergy' => 'allergies', 'vaccination' => 'vaccinations'];

    /** @param array<string, mixed> $payload
     * @return list<string> */
    public static function keys(array $payload): array
    {
        $keys = [];
        foreach (self::LISTS as $type => $list) {
            foreach (array_keys(is_array($payload[$list] ?? null) ? $payload[$list] : []) as $i) {
                $keys[] = $type.'-'.$i;
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $payload
     * @return array{type: string, fields: array<string, mixed>}|null */
    public static function entry(array $payload, string $key): ?array
    {
        if (! self::isKey($key)) {
            return null;
        }
        [$type, $index] = explode('-', $key);
        $fields = $payload[self::LISTS[$type]][(int) $index] ?? null;

        return is_array($fields) ? ['type' => $type, 'fields' => $fields] : null;
    }

    public static function isKey(string $key): bool
    {
        return preg_match('/^(visit|medication|allergy|vaccination)-(0|[1-9][0-9]?)$/D', $key) === 1;
    }
}
