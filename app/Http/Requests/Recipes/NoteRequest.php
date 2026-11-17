<?php

namespace App\Http\Requests\Recipes;

class NoteRequest extends RecipeScopedRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['present', 'nullable', 'string', 'max:'.(int) config('recipes.member_note_max')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['note.present' => 'recipes.error.state_invalid', 'note.*' => 'recipes.error.note_too_long'];
    }
}
