<?php

namespace App\Http\Requests\Wishlist;

use App\Wishlist\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,wishlist,edit and the Giving guard decide; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_string($amount)) {
            $amount = trim($amount);
            $this->merge(['amount' => $amount === '' ? null : $amount]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'string', 'regex:/^\d{1,9}([.,]\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail) {
                $minor = is_string($value) ? Money::toMinor($value) : null;
                if ($minor === null || $minor <= 0) {
                    $fail('wishlist.error.share_invalid');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount.regex' => 'wishlist.error.share_invalid', 'amount.string' => 'wishlist.error.share_invalid'];
    }

    public function amountMinor(): ?int
    {
        $amount = $this->validated('amount');

        return is_string($amount) ? Money::toMinor($amount) : null;
    }
}
