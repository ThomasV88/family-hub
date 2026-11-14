<?php

namespace App\Http\Requests\Health;

use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function authorize(): bool
    {
        // access:module,health,edit decides; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = now((string) config('family.timezone'))->toDateString();

        return [
            'name' => ['required', 'string', 'max:120', new ValidUtf8('health.person.err_name')],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'sex' => ['nullable', Rule::in(['female', 'male', 'unspecified'])],
            'blood_type' => ['nullable', Rule::in(self::BLOOD_TYPES)],
            'notes' => ['nullable', 'string', 'max:2000', new ValidUtf8('health.person.err_name')],
            'contacts' => ['nullable', 'array', 'max:'.(int) config('medical.max_contacts')],
            'contacts.*' => ['array'],
            'contacts.*.name' => ['required', 'string', 'max:120', new ValidUtf8('health.person.err_name')],
            'contacts.*.relation' => ['nullable', 'string', 'max:60', new ValidUtf8('health.error.load')],
            'contacts.*.phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\.\/\s-]{3,40}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'health.person.err_name',
            'name.string' => 'health.person.err_name',
            'name.max' => 'health.person.err_name',
            'contacts.max' => 'health.person.err_contacts_max',
            'contacts.*.name.required' => 'health.person.err_name',
            'contacts.*.name.max' => 'health.person.err_name',
            'contacts.*.phone.required' => 'health.person.err_phone',
            'contacts.*.phone.max' => 'health.person.err_phone',
            'contacts.*.phone.regex' => 'health.person.err_phone',
        ];
    }
}
