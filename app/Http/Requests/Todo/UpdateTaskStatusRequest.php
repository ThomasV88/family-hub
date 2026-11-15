<?php

namespace App\Http\Requests\Todo;

use App\Tasks\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,todo,edit and TaskAccess decide; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(TaskStatus::class)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['status.*' => 'todo.error.toggle_failed'];
    }
}
