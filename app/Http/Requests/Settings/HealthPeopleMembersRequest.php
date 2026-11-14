<?php

namespace App\Http\Requests\Settings;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Per-person member levels. Keys must be member ids, values none|view|edit, and granting needs the Health module. */
class HealthPeopleMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:admin decides; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'levels' => ['present', 'array', 'max:200'],
            'levels.*' => ['required', 'string', 'in:none,view,edit'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['levels.*' => 'health.access.failed'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $levels = (array) $this->input('levels');
            $ids = [];
            foreach (array_keys($levels) as $key) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    $validator->errors()->add('levels', 'health.access.failed');

                    return;
                }
                $ids[] = (int) $key;
            }
            $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
            $gate = app(AccessGate::class);
            foreach ($levels as $key => $level) {
                $user = $users->get((int) $key);
                if ($user === null) {
                    $validator->errors()->add('levels', 'health.access.failed');

                    return;
                }
                if ($level !== 'none' && $gate->level($user, Module::Health) === AccessLevel::None) {
                    $validator->errors()->add('levels', 'health.access.needs_module');

                    return;
                }
            }
        }];
    }

    /** @return array<int, string> */
    public function levels(): array
    {
        $out = [];
        foreach ((array) $this->validated('levels') as $key => $level) {
            $out[(int) $key] = (string) $level;
        }

        return $out;
    }
}
