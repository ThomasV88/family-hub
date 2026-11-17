<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class MailListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Trim, lower-case and de-duplicate the typed addresses. */
    protected function prepareForValidation(): void
    {
        $addresses = $this->input('addresses');
        if (! is_array($addresses)) {
            return;
        }

        $clean = [];
        foreach ($addresses as $address) {
            $clean[] = is_string($address) ? mb_strtolower(trim($address)) : $address;
        }

        // Non-strings stay in the list so validation rejects them.
        $this->merge(['addresses' => array_values(array_unique($clean, SORT_REGULAR))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'addresses' => ['required', 'array', 'min:1', 'max:'.(int) config('shopping.mail.per_send')],
            'addresses.*' => ['required', 'string', 'email:rfc', 'max:254'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'addresses.max' => 'shopping.mail.err_too_many',
            'addresses.*' => 'shopping.mail.err_invalid',
            'addresses' => 'shopping.mail.err_invalid',
        ];
    }
}
