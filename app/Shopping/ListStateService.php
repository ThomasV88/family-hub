<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\User;
use App\Shopping\Queries\ListQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lock, unlock, marks, finish (with carry-over) and copy. Every call locks the list row, then re-checks the status
 * under the lock. Targets are absolute, so a retried request converges instead of flipping.
 */
final class ListStateService
{
    public function __construct(
        private readonly ShoppingAccess $access,
        private readonly ResponsiblePerson $responsible,
        private readonly AccessGate $gate,
    ) {}

    public function setLocked(User $actor, int $listId, bool $locked): ShoppingList
    {
        return DB::transaction(function () use ($actor, $listId, $locked) {
            $list = ListQuery::lockForActor($actor, $listId, $this->access);
            abort_unless($this->access->isResponsible($actor, $list), 403);
            abort_if($list->status === ListStatus::Done, 409);

            if ($locked) {
                if ($list->status === ListStatus::Locked) {
                    return $list;
                }
                if (! ShoppingItem::query()->where('list_id', $list->id)->exists()) {
                    throw ValidationException::withMessages(['list' => 'shopping.error.lock_empty']);
                }
                $list->forceFill(['status' => ListStatus::Locked, 'locked_at' => now(), 'locked_by' => $actor->id])->save();
            } else {
                if ($list->status === ListStatus::Open) {
                    return $list;
                }
                // Marks are kept; finishing needs every item resolved again.
                $list->forceFill(['status' => ListStatus::Open, 'locked_at' => null, 'locked_by' => null])->save();
            }

            return $list->refresh();
        });
    }

    public function setItemState(User $actor, int $listId, int $itemId, ItemState $state): ShoppingItem
    {
        return DB::transaction(function () use ($actor, $listId, $itemId, $state) {
            $list = ListQuery::lockForActor($actor, $listId, $this->access);
            abort_unless($this->access->isResponsible($actor, $list), 403);
            abort_unless($list->status === ListStatus::Locked, 409);

            $item = ShoppingItem::query()->where('list_id', $list->id)->whereKey($itemId)->firstOrFail();
            if ($item->state !== $state) {
                $item->forceFill(['state' => $state, 'state_changed_at' => now(), 'state_changed_by' => $actor->id])->save();
                $list->touch();
            }

            return $item->refresh();
        });
    }

    /** @return array{list: ShoppingList, carry: ?ShoppingList} */
    public function finish(User $actor, int $listId, ?string $carryName): array
    {
        return DB::transaction(function () use ($actor, $listId, $carryName) {
            $list = ListQuery::lockForActor($actor, $listId, $this->access);
            abort_unless($this->access->isResponsible($actor, $list), 403);

            if ($list->status === ListStatus::Done) {
                return ['list' => $list, 'carry' => $this->existingCarry($list)];
            }
            abort_unless($list->status === ListStatus::Locked, 409);
            abort_if(ShoppingItem::query()->where('list_id', $list->id)->where('state', ItemState::Pending->value)->exists(), 409);

            $list->forceFill([
                'status' => ListStatus::Done,
                'done_at' => now(),
                'done_by' => $actor->id,
                'store_name' => $list->store_id === null ? $list->store_name : $list->store?->name,
            ])->save();

            $carry = null;
            $carryName = $carryName === null ? null : $this->cleanName($carryName);
            if ($carryName !== null) {
                $unavailable = ShoppingItem::query()->where('list_id', $list->id)
                    ->where('state', ItemState::Unavailable->value)->orderBy('position')->orderBy('id')->get();
                if ($unavailable->isNotEmpty()) {
                    $carry = $this->createCarry($list, $carryName, $unavailable);
                }
            }

            return ['list' => $list->refresh(), 'carry' => $carry];
        });
    }

    public function copy(User $actor, int $listId, string $name, bool $keep): ShoppingList
    {
        return DB::transaction(function () use ($actor, $listId, $name, $keep) {
            $source = ListQuery::lockForActor($actor, $listId, $this->access);
            abort_unless($this->access->canCopy($actor, $source), 403);

            $name = $this->cleanName($name) ?? throw ValidationException::withMessages(['name' => 'shopping.error.name_invalid']);
            $effective = $this->responsible->effective($source);
            $responsibleId = $keep ? $effective->id : $actor->id;

            $copy = new ShoppingList(['name' => $name, 'store_id' => $source->store_id, 'responsible_id' => $responsibleId]);
            $copy->forceFill([
                'creator_id' => $actor->id, 'status' => ListStatus::Open,
                'source_list_id' => $source->id, 'source_kind' => SourceKind::Copy,
            ])->save();

            if ($keep) {
                $this->copyMembers($source, $copy, $actor, $responsibleId);
                $this->addOriginalCreator($source, $copy, $actor, $responsibleId);
            }

            $this->copyItems($source->items()->get(), $copy);

            return $copy->refresh();
        });
    }

    private function createCarry(ShoppingList $done, string $name, Collection $unavailable): ShoppingList
    {
        try {
            // A savepoint, so a unique violation does not poison the surrounding transaction.
            return DB::transaction(function () use ($done, $name, $unavailable) {
                $carry = new ShoppingList([
                    'name' => $name, 'store_id' => $done->store_id,
                    'responsible_id' => $this->responsible->effective($done)->id,
                ]);
                $carry->forceFill([
                    'creator_id' => $done->creator_id, 'status' => ListStatus::Open,
                    'source_list_id' => $done->id, 'source_kind' => SourceKind::Carry,
                ])->save();

                $this->copyMembers($done, $carry, null, (int) $carry->responsible_id);
                $this->copyItems($unavailable, $carry);

                return $carry->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingCarry($done) ?? throw new \LogicException('Carry-over conflict without an existing carry list.');
        }
    }

    private function existingCarry(ShoppingList $done): ?ShoppingList
    {
        return ShoppingList::query()->where('source_list_id', $done->id)->where('source_kind', SourceKind::Carry->value)->first();
    }

    /** Contributors and viewers of the source, minus the new creator and the new responsible person. */
    private function copyMembers(ShoppingList $from, ShoppingList $to, ?User $newCreator, int $responsibleId): void
    {
        $skip = array_filter([$newCreator?->id, $responsibleId, $to->creator_id]);
        foreach (ShoppingListMember::query()->where('list_id', $from->id)->get() as $member) {
            if (in_array($member->user_id, $skip, true)) {
                continue;
            }
            $row = new ShoppingListMember;
            $row->forceFill(['list_id' => $to->id, 'user_id' => $member->user_id, 'role' => $member->role, 'created_at' => now()])->save();
        }
    }

    /** The original creator joins the copy as contributor (Shopping edit) or viewer (Shopping view), unless already in it. */
    private function addOriginalCreator(ShoppingList $source, ShoppingList $copy, User $copier, int $responsibleId): void
    {
        $original = $source->creator_id;
        if ($original === $copier->id || $original === $responsibleId) {
            return;
        }
        $user = User::query()->find($original);
        if ($user === null) {
            return;
        }
        $role = match (true) {
            $this->gate->allows($user, Module::Shopping, AccessLevel::Edit) => MemberRole::Contributor,
            $this->gate->allows($user, Module::Shopping, AccessLevel::View) => MemberRole::Viewer,
            default => null,
        };
        if ($role === null) {
            return;
        }
        ShoppingListMember::query()->where('list_id', $copy->id)->where('user_id', $original)->delete();
        $row = new ShoppingListMember;
        $row->forceFill(['list_id' => $copy->id, 'user_id' => $original, 'role' => $role, 'created_at' => now()])->save();
    }

    /** @param Collection<int, ShoppingItem> $items */
    private function copyItems(Collection $items, ShoppingList $target): void
    {
        $position = 0;
        foreach ($items as $item) {
            $row = new ShoppingItem([
                'list_id' => $target->id, 'catalog_item_id' => $item->catalog_item_id, 'name' => $item->name,
                'quantity' => $item->quantity, 'unit_id' => $item->unit_id, 'note' => $item->note,
                'position' => ++$position,
            ]);
            $row->forceFill(['state' => ItemState::Pending, 'created_by' => $item->created_by])->save();
        }
    }

    private function cleanName(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, (int) config('shopping.name_max.list'));
    }
}
