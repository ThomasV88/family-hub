<?php

namespace App\Http\Requests\Todo;

use App\Rules\HasTodoAccess;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
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
        ];
    }
}
