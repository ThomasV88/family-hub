<?php

namespace App\Http\Requests\Settings;

use App\Push\PushEndpointRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bytes = fn (int $length): Closure => function (string $attribute, mixed $value, Closure $fail) use ($length): void {
            $decoded = is_string($value) && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)
                ? base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true)
                : false;
            if ($decoded === false || strlen($decoded) !== $length) {
                $fail('validation.size.string')->translate(['size' => $length]);
            }
        };

        return [
            'endpoint' => ['required', 'string', new PushEndpointRule],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', $bytes(65)],
            'keys.auth' => ['required', 'string', $bytes(16)],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ];
    }
}
