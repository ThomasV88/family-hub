<?php

namespace App\Rules;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ModulePermission;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The value must be the id of an existing user holding To-do view or better. */
class HasTodoAccess implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ok = is_numeric($value)
            && User::query()->whereKey((int) $value)->exists()
            && ModulePermission::query()
                ->where('user_id', (int) $value)
                ->where('module', Module::Todo->value)
                ->where('level', '>=', AccessLevel::View->value)
                ->exists();

        if (! $ok) {
            $fail('todo.error.assignee');
        }
    }
}
