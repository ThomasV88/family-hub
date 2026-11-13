<?php

namespace App\Http\Requests\Wishlist;

use App\Wishlist\Occasions\OccasionAccess;
use App\Wishlist\WishlistMembers;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class OccasionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Create is gated by access:module,wishlist,edit; changing one needs creator or admin (404 for name-day rows).
        if ($this->isMethod('POST')) {
            return true;
        }
        $id = $this->route('occasion');
        abort_unless(is_string($id) && ctype_digit($id) && strlen($id) < 10, 404);
        app(OccasionAccess::class)->editableCustom($this->user(), (int) $id);

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
            'title' => ['required', 'string', 'max:100'],
            'date' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                    || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1900 || (int) $m[1] > 2200) {
                    $fail('occasions.error.date_invalid');
                }
            }],
            'yearly' => ['required', 'boolean'],
            'owner_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && ! in_array((int) $value, app(WishlistMembers::class)->ids($this->user()), true)) {
                    $fail('occasions.error.save_failed');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'occasions.error.title_required',
            'title.string' => 'occasions.error.title_required',
            'title.max' => 'occasions.error.title_required',
            'date.required' => 'occasions.error.date_invalid',
            'date.string' => 'occasions.error.date_invalid',
            'yearly.required' => 'occasions.error.date_invalid',
            'yearly.boolean' => 'occasions.error.date_invalid',
        ];
    }
}
