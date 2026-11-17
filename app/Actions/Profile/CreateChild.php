<?php

namespace App\Actions\Profile;

use App\Access\Preset;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use App\Wishlist\WishlistManagerSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Creates a child account: no e-mail, cannot sign in, Child permission preset, chosen parents manage the wish list. */
class CreateChild
{
    public function __construct(private readonly WishlistManagerSync $managers, private readonly AuditLogger $audit) {}

    /** @param  list<int>  $parentIds */
    public function handle(User $actor, string $name, array $parentIds): User
    {
        return DB::transaction(function () use ($actor, $name, $parentIds) {
            $child = new User;
            $child->forceFill([
                'name' => $name,
                'email' => null,
                'sign_in_disabled' => true,
                'password' => Hash::make(Str::random(64)),
                'password_set' => false,
                'locale' => $actor->locale,
                'is_admin' => false,
            ])->save();

            foreach (Preset::Child->grants() as $module => $level) {
                $child->modulePermissions()->updateOrCreate(['module' => $module], ['level' => $level]);
            }

            $this->managers->sync($child, array_values(array_unique(array_map('intval', $parentIds))), $actor);
            $this->audit->record(AuditEvent::ChildCreated, $actor, [], $child);

            return $child;
        });
    }
}
