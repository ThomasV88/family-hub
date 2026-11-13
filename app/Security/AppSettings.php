<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;

/** Small key/value store (JSON values) for admin-controlled settings. Memoised per instance (bind `scoped`). */
class AppSettings
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $raw = DB::table('app_settings')->where('key', $key)->value('value');
            $this->cache[$key] = $raw === null ? null : json_decode($raw, true);
            if ($raw === null) {
                return $default;
            }
        }

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('app_settings')->upsert(
            [['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
        $this->cache[$key] = $value;
    }
}
