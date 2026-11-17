<?php

namespace App\Http\Requests\Shopping;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Header, people and reminder of a list. Only the keys that were sent reach the service. */
class UpdateListRequest extends FormRequest
{
    public const REMIND_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.(int) config('shopping.name_max.list')],
            'store_id' => ['sometimes', 'nullable', 'integer'],
            'responsible_id' => ['sometimes', 'nullable', 'integer'],
            'contributor_ids' => ['sometimes', 'array'],
            'contributor_ids.*' => ['integer'],
            'viewer_ids' => ['sometimes', 'array'],
            'viewer_ids.*' => ['integer'],
            'remind_at' => ['sometimes', 'nullable', 'date_format:'.self::REMIND_FORMAT],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('remind_at') || ! is_string($this->input('remind_at'))) {
                return;
            }
            if ($this->remindUtc() <= CarbonImmutable::now()) {
                $validator->errors()->add('remind_at', 'shopping.error.reminder_past');
            }
        }];
    }

    /** The payload for ListService::updateSettings: sent keys only, the reminder as a UTC instant. */
    public function settings(): array
    {
        $data = $this->validated();
        if (array_key_exists('remind_at', $data)) {
            $data['remind_at'] = is_string($data['remind_at']) ? $this->remindUtc() : null;
        }

        return $data;
    }

    /** The local family-timezone wall clock time as a UTC instant. */
    private function remindUtc(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::REMIND_FORMAT, (string) $this->input('remind_at'), config('family.timezone'))
            ->setTimezone('UTC');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'shopping.error.name_invalid',
            'store_id.*' => 'shopping.error.store_unknown',
            'responsible_id.*' => 'shopping.error.responsible_not_eligible',
            'contributor_ids.*' => 'shopping.error.member_not_eligible',
            'viewer_ids.*' => 'shopping.error.member_not_eligible',
            'remind_at.*' => 'shopping.error.reminder_invalid',
        ];
    }
}
