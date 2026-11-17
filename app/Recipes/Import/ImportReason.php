<?php

namespace App\Recipes\Import;

/** The coarse, content-free reason the browser gets for a failed import. The stored failure code never leaves the server. */
final class ImportReason
{
    /** failure_code => fetch | no_recipe | unavailable */
    private const MAP = [
        'fetch_failed' => 'fetch',
        'no_recipe' => 'no_recipe',
        'invalid_output' => 'no_recipe',
        'no_output' => 'no_recipe',
        'output_too_large' => 'no_recipe',
        'cli_error' => 'no_recipe',
        'timeout' => 'no_recipe',
        'idle_timeout' => 'no_recipe',
        'worker_lost' => 'no_recipe',
        'quota_exhausted' => 'unavailable',
        'quota_timeout' => 'unavailable',
        'no_agent' => 'unavailable',
        'auth' => 'unavailable',
        'notice_required' => 'unavailable',
        'preflight' => 'unavailable',
    ];

    public static function for(?string $failureCode): string
    {
        return self::MAP[(string) $failureCode] ?? 'unavailable';
    }
}
