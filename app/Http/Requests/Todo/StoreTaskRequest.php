<?php

namespace App\Http\Requests\Todo;

use App\Rules\HasTodoAccess;
use App\Tasks\TaskRecurrence;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,todo,edit decides; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }

        // Numeric strings from a form post become integers so the pattern allowlist can stay strict.
        $recurrence = $this->input('recurrence');
        if (is_array($recurrence)) {
            foreach (['interval', 'monthDay'] as $key) {
                if (isset($recurrence[$key]) && is_string($recurrence[$key]) && ctype_digit($recurrence[$key])) {
                    $recurrence[$key] = (int) $recurrence[$key];
                }
            }
            $this->merge(['recurrence' => $recurrence]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'assignee_id' => ['nullable', 'integer', new HasTodoAccess],
            'visibility' => ['sometimes', Rule::enum(TaskVisibility::class)],
            'member_ids' => ['exclude_unless:visibility,selected', 'required', 'array', 'min:1'],
            'member_ids.*' => ['integer', new HasTodoAccess],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i', Rule::prohibitedIf(fn () => blank($this->input('due_date')))],
            'remind_before_minutes' => ['nullable', 'integer', Rule::in([0, 15, 60, 1440])],
            'recurrence' => ['nullable', 'array'],
            'recurrence_mode' => ['required_with:recurrence', 'nullable', Rule::in(['schedule', 'completion'])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $remind = $this->input('remind_before_minutes');
            if ($remind !== null && ! $validator->errors()->has('remind_before_minutes')) {
                if (blank($this->input('due_date'))) {
                    $validator->errors()->add('remind_before_minutes', 'todo.reminder.need_date');
                } elseif (in_array((int) $remind, [15, 60], true) && blank($this->input('due_time'))) {
                    $validator->errors()->add('remind_before_minutes', 'todo.reminder.need_time');
                }
            }

            $recurrence = $this->input('recurrence');
            if (! is_array($recurrence) || $validator->errors()->has('recurrence')) {
                return;
            }
            if (blank($this->input('due_date'))) {
                $validator->errors()->add('recurrence', 'todo.repeat.need_date');

                return;
            }
            $interval = $recurrence['interval'] ?? 1;
            if (! is_int($interval) || $interval < 1 || $interval > 99) {
                $validator->errors()->add('recurrence.interval', 'todo.error.interval');

                return;
            }
            if (($recurrence['kind'] ?? null) === 'weekly' && empty($recurrence['weekdays'])) {
                $validator->errors()->add('recurrence.weekdays', 'todo.error.weekdays');

                return;
            }
            try {
                TaskRecurrence::pattern($recurrence, (string) $this->input('due_date'));
            } catch (InvalidArgumentException) {
                $validator->errors()->add('recurrence', 'todo.error.save_failed');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'todo.error.title_required',
            'title.string' => 'todo.error.title_required',
            'title.max' => 'todo.error.title_long',
            'notes.string' => 'todo.error.notes_long',
            'notes.max' => 'todo.error.notes_long',
            'status.*' => 'todo.error.save_failed',
            'visibility.*' => 'todo.error.save_failed',
            'member_ids.required' => 'todo.error.selected_none',
            'member_ids.min' => 'todo.error.selected_none',
            'member_ids.array' => 'todo.error.selected_none',
            'member_ids.*.integer' => 'todo.error.assignee',
            'assignee_id.integer' => 'todo.error.assignee',
            'due_date.date_format' => 'todo.error.date_invalid',
            'due_time.date_format' => 'todo.error.time_invalid',
            'due_time.prohibited' => 'todo.error.time_invalid',
            'remind_before_minutes.*' => 'todo.error.save_failed',
            'recurrence.array' => 'todo.error.save_failed',
            'recurrence_mode.*' => 'todo.error.save_failed',
        ];
    }
}
