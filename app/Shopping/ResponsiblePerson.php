<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\User;

/**
 * Who really acts as the responsible person of a list. The stored responsible person counts only while they can sign in
 * and hold Shopping edit; otherwise the creator acts while eligible, otherwise the first eligible contributor (lowest
 * user id). Resolved lazily, nothing is written back. When nobody is eligible, effective() still returns the creator so
 * the list stays readable, hasActive() is false, and an administrator holding Shopping edit may act as the responsible
 * person (ShoppingAccess), so a list is never left that nobody can unlock.
 */
final class ResponsiblePerson
{
    public function __construct(private readonly AccessGate $gate) {}

    public function eligible(User $user): bool
    {
        return $user->canSignIn() && $this->gate->allows($user, Module::Shopping, AccessLevel::Edit);
    }

    public function effective(ShoppingList $list): User
    {
        return $this->active($list) ?? $this->creator($list);
    }

    /** True while a stored responsible person, the creator or a contributor is eligible to act. */
    public function hasActive(ShoppingList $list): bool
    {
        return $this->active($list) !== null;
    }

    /** True when somebody other than the stored responsible person acts (missing, no longer eligible). */
    public function isFallback(ShoppingList $list): bool
    {
        $stored = $this->stored($list);

        return $stored === null || ! $this->eligible($stored);
    }

    /** Name of the ineligible responsible person while that row still exists, else null. */
    public function previousName(ShoppingList $list): ?string
    {
        $stored = $this->stored($list);

        return $stored !== null && ! $this->eligible($stored) ? $stored->name : null;
    }

    private function active(ShoppingList $list): ?User
    {
        $stored = $this->stored($list);
        if ($stored !== null && $this->eligible($stored)) {
            return $stored;
        }
        $creator = $this->creator($list);
        if ($this->eligible($creator)) {
            return $creator;
        }

        $members = ShoppingListMember::query()->where('list_id', $list->id)->where('role', MemberRole::Contributor->value)
            ->orderBy('user_id')->with('user')->get();
        foreach ($members as $member) {
            if ($member->user !== null && $this->eligible($member->user)) {
                return $member->user;
            }
        }

        return null;
    }

    private function stored(ShoppingList $list): ?User
    {
        if ($list->responsible_id === null) {
            return null;
        }

        return $list->relationLoaded('responsible') && $list->responsible?->id === $list->responsible_id
            ? $list->responsible
            : User::query()->find($list->responsible_id);
    }

    private function creator(ShoppingList $list): User
    {
        return $list->relationLoaded('creator') && $list->creator?->id === $list->creator_id
            ? $list->creator
            : User::query()->findOrFail($list->creator_id);
    }
}
