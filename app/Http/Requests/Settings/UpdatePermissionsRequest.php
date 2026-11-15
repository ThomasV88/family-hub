<?php

namespace App\Http\Requests\Settings;

use App\Access\Module;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Exactly the four module keys with an enum level; anything else (settings, is_admin) is rejected. */
class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The access:admin route middleware decides; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (Module::cases() as $module) {
            $rules[$module->value] = ['required', 'string', Rule::in(['none', 'view', 'edit'])];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowed = array_map(fn (Module $m) => $m->value, Module::cases());
            foreach (array_keys($this->request->all()) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add((string) $key, 'Unknown field.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function levels(): array
    {
        return $this->safe()->only(array_map(fn (Module $m) => $m->value, Module::cases()));
    }
}
