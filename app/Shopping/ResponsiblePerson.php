<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingList;
use App\Models\User;

/**
 * Who really acts as the responsible person of a list. The stored responsible person counts only while they can sign in
 * and hold Shopping edit; otherwise the creator acts (resolved lazily, nothing is written back).
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
        $stored = $this->stored($list);
        if ($stored !== null && $this->eligible($stored)) {
            return $stored;
        }

        return $this->creator($list);
    }

    /** True when the creator acts because the stored responsible person is missing or no longer eligible. */
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
