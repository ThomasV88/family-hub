<?php

namespace App\Http\Requests\Shopping;

/** Shared quantity input rules: decimal comma allowed, 0.01 to 999.99, at most two decimals. */
final class Quantity
{
    /** "1,25" and " 1 ,25 " become "1.25"; anything else is left for the rules to refuse. */
    public static function normalise(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return str_replace(',', '.', (string) preg_replace('/\s+/u', '', $value));
    }

    /** @return list<mixed> */
    public static function rules(bool $nullable): array
    {
        return [
            $nullable ? 'nullable' : 'required',
            'bail',
            'regex:/^\d{1,3}(\.\d{1,2})?$/',
            fn (string $attribute, mixed $value, \Closure $fail) => (float) $value > 0 && (float) $value <= (float) config('shopping.quantity_max')
                ? null
                : $fail('shopping.error.quantity'),
        ];
    }
}
