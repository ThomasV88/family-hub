<?php

namespace App\Http\Requests\Wishlist;

use App\Wishlist\Occasions\NameDayCalendar;
use App\Wishlist\Occasions\OccasionAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MemberDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Unknown ids, non-members and unrelated members are all the same 403, before any validation answer.
        $member = $this->route('member');

        return is_string($member) && ctype_digit($member) && strlen($member) < 10
            && app(OccasionAccess::class)->canEditDates($this->user(), (int) $member);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'birth_date' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $fail('occasions.error.date_invalid');

                    return;
                }
                $today = CarbonImmutable::now((string) config('family.timezone'))->format('Y-m-d');
                if ($value > $today || (int) $m[1] < 1900) {
                    $fail('occasions.error.birth_future');
                }
            }],
            'nameday' => ['sometimes', 'nullable', 'array'],
            'nameday.calendar' => ['required_with:nameday', 'string', 'in:sk,pl'],
            'nameday.name' => ['required_with:nameday', 'string', 'max:60'],
            'nameday.month' => ['required_with:nameday', 'integer', 'between:1,12'],
            'nameday.day' => ['required_with:nameday', 'integer', 'between:1,31', function (string $attribute, mixed $value, Closure $fail) {
                $nameday = $this->input('nameday');
                if (! is_array($nameday) || ! is_string($nameday['calendar'] ?? null) || ! is_string($nameday['name'] ?? null)
                    || ! in_array($nameday['calendar'], NameDayCalendar::calendars(), true)
                    || ! NameDayCalendar::has($nameday['calendar'], $nameday['name'], (int) ($nameday['month'] ?? 0), (int) $value)) {
                    $fail('occasions.error.nameday_required');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nameday.calendar.required_with' => 'occasions.error.nameday_required',
            'nameday.calendar.in' => 'occasions.error.nameday_required',
            'nameday.name.required_with' => 'occasions.error.nameday_required',
            'nameday.month.required_with' => 'occasions.error.nameday_required',
            'nameday.day.required_with' => 'occasions.error.nameday_required',
            'nameday.name.max' => 'occasions.error.nameday_required',
            'nameday.month.between' => 'occasions.error.nameday_required',
            'nameday.day.between' => 'occasions.error.nameday_required',
            'nameday.month.integer' => 'occasions.error.nameday_required',
            'nameday.day.integer' => 'occasions.error.nameday_required',
            'nameday.calendar.string' => 'occasions.error.nameday_required',
            'nameday.name.string' => 'occasions.error.nameday_required',
            'birth_date.string' => 'occasions.error.date_invalid',
            'nameday.array' => 'occasions.error.nameday_required',
        ];
    }
}
