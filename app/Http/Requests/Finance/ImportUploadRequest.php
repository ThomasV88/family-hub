<?php

namespace App\Http\Requests\Finance;

use App\Finance\Import\ProfileSpec;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceImportProfile;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Upload that stages a draft batch. The account is resolved through AccountQuery::findVisible with one message for
 * hidden and nonexistent ids. Settings go through ProfileSpec::fromArray (allowlists), or a saved profile is used.
 */
class ImportUploadRequest extends FormRequest
{
    private ?FinanceAccount $account = null;

    private ?FinanceImportProfile $profile = null;

    private ?ProfileSpec $spec = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : null;
        }
        if (is_array($settings)) {
            $settings = $this->coerce($settings);
        }
        $merge = [];
        if ($settings !== null || $this->has('settings')) {
            $merge['settings'] = $settings;
        }
        foreach (['account_id', 'profile_id', 'profile_name'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $merge[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($merge);
    }

    /**
     * Multipart sends every scalar as a string: integers and empty cells are restored before the allowlist check.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function coerce(array $s): array
    {
        if (isset($s['headerRow']) && is_string($s['headerRow']) && ctype_digit($s['headerRow'])) {
            $s['headerRow'] = (int) $s['headerRow'];
        }
        if (isset($s['columns']) && is_array($s['columns'])) {
            foreach ($s['columns'] as $role => $col) {
                if (! is_array($col)) {
                    continue;
                }
                if (array_key_exists('index', $col)) {
                    $col['index'] = is_string($col['index']) ? (ctype_digit($col['index']) ? (int) $col['index'] : null) : $col['index'];
                }
                if (array_key_exists('name', $col) && $col['name'] === '') {
                    $col['name'] = null;
                }
                $s['columns'][$role] = $col;
            }
        }

        return $s;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'account_id' => ['required', 'string'],
            'profile_id' => ['sometimes', 'nullable', 'string'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'save_profile' => ['sometimes', 'boolean'],
            'profile_name' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'finance.imp.err_type',
            'file.file' => 'finance.imp.err_type',
            'file.uploaded' => 'finance.imp.err_size',
            'file.max' => 'finance.imp.err_size',
            'file.extensions' => 'finance.imp.err_type',
            'account_id.required' => 'finance.error.account',
            'account_id.string' => 'finance.error.account',
            'profile_id.string' => 'finance.imp.err_profile',
            'settings.array' => 'finance.imp.err_profile',
            'save_profile.boolean' => 'finance.imp.err_profile_name',
            'profile_name.string' => 'finance.imp.err_profile_name',
            'profile_name.max' => 'finance.imp.err_profile_name',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [fn (Validator $v) => $this->resolve($v)];
    }

    private function resolve(Validator $v): void
    {
        if (! $v->errors()->has('account_id')) {
            $this->account = app(AccountQuery::class)->findVisible(Viewer::for($this->user()), (string) $this->input('account_id'));
            if ($this->account === null) {
                $v->errors()->add('account_id', 'finance.error.account');
            }
        }

        $imports = app(ImportQuery::class);
        $profileId = $this->input('profile_id');
        if (is_string($profileId) && ! $v->errors()->has('profile_id')) {
            $this->profile = $imports->findProfile($profileId);
            if ($this->profile === null) {
                $v->errors()->add('profile_id', 'finance.imp.err_profile');

                return;
            }
            try {
                $this->spec = ProfileSpec::fromArray((array) $this->profile->settings);
            } catch (InvalidArgumentException) {
                $v->errors()->add('profile_id', 'finance.imp.err_profile');
            }

            return;
        }

        if ($v->errors()->has('settings') || $v->errors()->has('profile_id')) {
            return;
        }
        $settings = $this->input('settings');
        if (! is_array($settings)) {
            $v->errors()->add('settings', 'finance.imp.err_profile');

            return;
        }
        try {
            $this->spec = ProfileSpec::fromArray($settings);
        } catch (InvalidArgumentException) {
            $v->errors()->add('settings', 'finance.imp.err_profile');

            return;
        }

        if ($this->boolean('save_profile')) {
            $name = $this->input('profile_name');
            if (! is_string($name) || $name === '' || $imports->profileNameTaken($name)) {
                $v->errors()->add('profile_name', 'finance.imp.err_profile_name');
            }
        }
    }

    public function account(): FinanceAccount
    {
        return $this->account ?? abort(404);
    }

    public function spec(): ProfileSpec
    {
        return $this->spec ?? abort(422);
    }

    public function usesSavedProfile(): bool
    {
        return $this->profile !== null;
    }

    /** Profile name to save the submitted settings under, or null. */
    public function profileNameToSave(): ?string
    {
        if ($this->profile !== null || ! $this->boolean('save_profile')) {
            return null;
        }

        return (string) $this->input('profile_name');
    }
}
