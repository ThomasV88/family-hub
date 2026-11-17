<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Replaces the contributors and viewers of a list. Call only with the list row already locked. */
final class ListMembers
{
    public function __construct(private readonly AccessGate $gate) {}

    /**
     * @param  list<int|string>  $contributorIds
     * @param  list<int|string>  $viewerIds
     */
    public function sync(ShoppingList $locked, array $contributorIds, array $viewerIds): void
    {
        $contributors = array_values(array_unique(array_map('intval', $contributorIds)));
        $viewers = array_values(array_unique(array_map('intval', $viewerIds)));

        if (array_intersect($contributors, $viewers) !== []) {
            throw ValidationException::withMessages(['viewers' => 'shopping.error.member_overlap']);
        }
        $reserved = array_filter([(int) $locked->creator_id, $locked->responsible_id === null ? null : (int) $locked->responsible_id]);
        if (array_intersect($contributors, $reserved) !== []) {
            throw ValidationException::withMessages(['contributors' => 'shopping.error.member_overlap']);
        }
        if (array_intersect($viewers, $reserved) !== []) {
            throw ValidationException::withMessages(['viewers' => 'shopping.error.member_overlap']);
        }

        $this->assertEligible($contributors, 'contributors', AccessLevel::Edit);
        $this->assertEligible($viewers, 'viewers', AccessLevel::View);

        $wanted = [];
        foreach ($contributors as $id) {
            $wanted[$id] = MemberRole::Contributor;
        }
        foreach ($viewers as $id) {
            $wanted[$id] = MemberRole::Viewer;
        }

        ShoppingListMember::query()->where('list_id', $locked->id)->whereNotIn('user_id', array_keys($wanted))->delete();
        foreach ($wanted as $userId => $role) {
            $row = ShoppingListMember::query()->where('list_id', $locked->id)->where('user_id', $userId)->first();
            if ($row === null) {
                $row = new ShoppingListMember;
                $row->forceFill(['list_id' => $locked->id, 'user_id' => $userId, 'role' => $role, 'created_at' => now()])->save();
            } elseif ($row->role !== $role) {
                ShoppingListMember::query()->where('list_id', $locked->id)->where('user_id', $userId)->update(['role' => $role->value]);
            }
        }
    }

    /** @param list<int> $ids */
    private function assertEligible(array $ids, string $key, AccessLevel $needed): void
    {
        if ($ids === []) {
            return;
        }
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $user = $users->get($id);
            // Children cannot sign in but may take part as contributors or viewers.
            if ($user === null || ! $this->gate->allows($user, Module::Shopping, $needed)) {
                throw ValidationException::withMessages([$key => 'shopping.error.member_not_eligible']);
            }
        }
    }
}
