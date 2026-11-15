<?php

namespace App\Http\Requests\Wishlist;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WishRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,wishlist,edit and WishAccess::canEditList decide; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('title'))) {
            $merge['title'] = trim($this->input('title'));
        }
        if (is_string($this->input('price'))) {
            $price = trim($this->input('price'));
            $merge['price'] = $price === '' ? null : $price;
        }
        $urls = $this->input('urls');
        if (is_array($urls)) {
            $merge['urls'] = array_values(array_filter($urls, function ($row) {
                if (! is_array($row)) {
                    return true;
                }
                $url = $row['url'] ?? null;

                return ! (is_string($url) ? trim($url) === '' : $url === null);
            }));
            $merge['urls'] = array_map(function ($row) {
                if (is_array($row) && is_string($row['url'] ?? null)) {
                    $row['url'] = trim($row['url']);
                }

                return $row;
            }, $merge['urls']);
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:'.config('wishlist.title_max')],
            'notes' => ['nullable', 'string', 'max:'.config('wishlist.notes_max')],
            'size_notes' => ['nullable', 'string', 'max:'.config('wishlist.size_notes_max')],
            'price' => ['nullable', 'string', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'currency' => ['sometimes', Rule::in(config('wishlist.currencies'))],
            'priority' => ['sometimes', Rule::in(['top', 'love', 'nice'])],
            'urls' => ['sometimes', 'nullable', 'array', 'max:'.config('wishlist.max_urls')],
            'urls.*' => ['array'],
            'urls.*.url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! self::isWebUrl($value)) {
                    $fail('wishlist.error.url_invalid');
                }
            }],
            'urls.*.label' => ['nullable', 'string', 'max:100'],
        ];

        // owner_id is accepted on create only; on update it is never read.
        if ($this->isMethod('POST')) {
            $rules['owner_id'] = ['sometimes', 'nullable', 'integer'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'wishlist.error.title_required',
            'title.max' => 'wishlist.error.save_failed',
            'price.regex' => 'wishlist.error.price_invalid',
            'urls.*.url.required' => 'wishlist.error.url_invalid',
            'urls.*.url.max' => 'wishlist.error.url_invalid',
            'urls.*.url.string' => 'wishlist.error.url_invalid',
        ];
    }

    /** Only absolute http(s) addresses with a host; javascript:, data:, file:, ftp: and friends are refused. */
    public static function isWebUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
