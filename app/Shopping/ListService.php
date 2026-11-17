<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\ShoppingStore;
use App\Models\User;
use App\Shopping\Queries\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** List header, people, reminder and deletion. Every mutation runs under the list row lock. */
final class ListService
{
    public function __construct(
        private readonly ShoppingAccess $access,
        private readonly ResponsiblePerson $responsible,
        private readonly ListMembers $members,
        private readonly AccessGate $gate,
    ) {}

    public function create(User $actor, string $name, ?int $storeId, ?int $responsibleId): ShoppingList
    {
        abort_unless($this->gate->allows($actor, Module::Shopping, AccessLevel::Edit), 403);

        $name = $this->cleanName($name);
        $this->assertStore($storeId);
        $responsibleId ??= $actor->id;
        $this->assertResponsible($responsibleId);

        return DB::transaction(function () use ($actor, $name, $storeId, $responsibleId) {
            $list = new ShoppingList(['name' => $name, 'store_id' => $storeId, 'responsible_id' => $responsibleId]);
            $list->forceFill(['creator_id' => $actor->id, 'status' => ListStatus::Open])->save();

            return $list->refresh();
        });
    }

    /**
     * @param  array{name?: string, store_id?: ?int, responsible_id?: ?int, contributor_ids?: list<int>, viewer_ids?: list<int>, remind_at?: ?CarbonImmutable}  $data
     */
    public function updateSettings(User $actor, int $listId, array $data): ShoppingList
    {
        return DB::transaction(function () use ($actor, $listId, $data) {
            $list = ListQuery::lockForActor($actor, $listId, $this->access);

            abort_unless($this->access->mayManage($actor, $list), 403);
            abort_if($list->status === ListStatus::Done, 409);

            $touchesOpenOnly = array_key_exists('name', $data) || array_key_exists('store_id', $data)
                || array_key_exists('contributor_ids', $data) || array_key_exists('viewer_ids', $data);
            if ($touchesOpenOnly) {
                abort_unless($list->status === ListStatus::Open, 409);
            }
            $touchesResponsible = array_key_exists('responsible_id', $data) || array_key_exists('remind_at', $data);
            if ($touchesResponsible && $list->status === ListStatus::Locked) {
                abort_unless($this->access->isResponsible($actor, $list), 403);
            }

            if (array_key_exists('name', $data)) {
                $list->name = $this->cleanName((string) $data['name']);
            }
            if (array_key_exists('store_id', $data)) {
                $this->assertStore($data['store_id']);
                $list->store_id = $data['store_id'];
            }
            $responsibleChanged = false;
            $previousId = null;
            if (array_key_exists('responsible_id', $data)) {
                $newId = $data['responsible_id'] ?? $actor->id;
                if ($newId !== $list->responsible_id) {
                    $this->assertResponsible($newId);
                    $previousId = $list->responsible_id;
                    $list->responsible_id = $newId;
                    $responsibleChanged = true;
                }
            }
            if (array_key_exists('remind_at', $data)) {
                $new = $data['remind_at'];
                $old = $list->remind_at;
                if (($new?->getTimestamp()) !== ($old?->getTimestamp())) {
                    $list->remind_at = $new;
                    $list->reminder_enqueued_at = null;
                }
            }
            $list->save();

            if (array_key_exists('contributor_ids', $data) || array_key_exists('viewer_ids', $data) || $responsibleChanged) {
                $current = ShoppingListMember::query()->where('list_id', $list->id)->get();
                $contributors = $data['contributor_ids'] ?? $current->where('role', MemberRole::Contributor)->pluck('user_id')->all();
                $viewers = $data['viewer_ids'] ?? $current->where('role', MemberRole::Viewer)->pluck('user_id')->all();
                if (! array_key_exists('contributor_ids', $data) && ! array_key_exists('viewer_ids', $data)) {
                    // Only the responsible person changed: a person never holds two roles, so drop the new one from the members.
                    $contributors = array_values(array_diff($contributors, [$list->responsible_id]));
                    $viewers = array_values(array_diff($viewers, [$list->responsible_id]));
                }
                if ($previousId !== null) {
                    [$contributors, $viewers] = $this->keepPreviousResponsible($list, $previousId, $contributors, $viewers);
                }
                $this->members->sync($list, $contributors, $viewers);
            }

            return $list->refresh();
        });
    }

    /**
     * The person who handed the list over keeps access: as contributor (they could act, so they hold Shopping edit) or,
     * if that no longer holds, as viewer. The creator needs no row, and someone the request placed explicitly stays as asked.
     *
     * @param  list<int|string>  $contributors
     * @param  list<int|string>  $viewers
     * @return array{0: list<int|string>, 1: list<int|string>}
     */
    private function keepPreviousResponsible(ShoppingList $list, int $previousId, array $contributors, array $viewers): array
    {
        $listed = array_map('intval', array_merge($contributors, $viewers));
        if ($previousId === (int) $list->creator_id || $previousId === (int) $list->responsible_id || in_array($previousId, $listed, true)) {
            return [$contributors, $viewers];
        }
        $previous = User::query()->find($previousId);
        if ($previous === null) {
            return [$contributors, $viewers];
        }
        if ($this->gate->allows($previous, Module::Shopping, AccessLevel::Edit)) {
            $contributors[] = $previousId;
        } elseif ($this->gate->allows($previous, Module::Shopping, AccessLevel::View)) {
            $viewers[] = $previousId;
        }

        return [$contributors, $viewers];
    }

    public function delete(User $actor, int $listId): void
    {
        DB::transaction(function () use ($actor, $listId) {
            $list = ListQuery::lockForActor($actor, $listId, $this->access);

            abort_unless($this->access->mayManage($actor, $list), 403);
            abort_unless($list->status === ListStatus::Open, 409);

            $list->delete();
        });
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > (int) config('shopping.name_max.list')) {
            throw ValidationException::withMessages(['name' => 'shopping.error.name_invalid']);
        }

        return $name;
    }

    private function assertStore(?int $storeId): void
    {
        if ($storeId !== null && ! ShoppingStore::query()->whereKey($storeId)->exists()) {
            throw ValidationException::withMessages(['store_id' => 'shopping.error.store_unknown']);
        }
    }

    private function assertResponsible(int $userId): void
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $this->responsible->eligible($user)) {
            throw ValidationException::withMessages(['responsible_id' => 'shopping.error.responsible_not_eligible']);
        }
    }
}
