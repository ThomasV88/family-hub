<?php

namespace App\Rules;

use App\Models\User;
use App\Wishlist\WishlistManagerSync;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The value must be an existing member who can sign in and holds Wishlist Edit (a child or view-only member cannot manage a list). */
class EligibleWishlistManager implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $member = is_numeric($value) ? User::query()->find((int) $value) : null;

        if ($member === null || ! WishlistManagerSync::isEligible($member)) {
            $fail('settings.members.child.err_parent');
        }
    }
}
