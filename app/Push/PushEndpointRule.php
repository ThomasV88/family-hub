<?php

namespace App\Push;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Only real push-service endpoints: the server will POST to this URL later (SSRF guard). */
class PushEndpointRule implements ValidationRule
{
    public const HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::allowed($value)) {
            $fail('validation.url')->translate();
        }
    }

    public static function allowed(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return false;
        }
        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_contains($host, '[')) {
            return false;
        }
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
