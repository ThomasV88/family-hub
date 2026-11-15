<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Text that is not valid UTF-8 would reach Postgres as an encoding error (SQLSTATE 22021) and surface as a 500, so it
 * is refused up front with the field's own message key.
 */
final class ValidUtf8 implements ValidationRule
{
    public function __construct(private readonly string $messageKey) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail($this->messageKey);
        }
    }
}
