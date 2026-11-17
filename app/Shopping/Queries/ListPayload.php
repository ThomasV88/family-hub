<?php

namespace App\Shopping\Queries;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\PushSubscription;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use App\Shopping\MemberRole;
use App\Shopping\ResponsiblePerson;
use App\Shopping\ShoppingAccess;
use Carbon\CarbonInterface;

/**
 * Inertia props for Shopping/Index and Shopping/Show. Explicit field lists only: names and ids, never an e-mail
 * address, a file path or another member's preference (the one exception is the single push boolean for the reminder).
 */
final class ListPayload
{
    public function __construct(
        private readonly ShoppingAccess $access,
        private readonly ResponsiblePerson $responsible,
        private readonly AccessGate $gate,
        private readonly NotificationPreferences $preferences,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $user, string $tab, int $page): array
    {
        $canCreate = $this->gate->allows($user, Module::Shopping, AccessLevel::Edit);
        $open = ListQuery::visibleTo($user)
            ->whereIn('shopping_lists.status', [ListStatus::Open->value, ListStatus::Locked->value])
            ->with(['store', 'responsible', 'creator', 'members' => fn ($q) => $q->where('user_id', $user->id)])
            ->withCount([
                'items as items_count',
                'items as bought_count' => fn ($q) => $q->where('state', ItemState::Bought->value),
                'items as unavailable_count' => fn ($q) => $q->where('state', ItemState::Unavailable->value),
            ])
            ->orderByRaw('shopping_lists.remind_at asc nulls last')
            ->orderByDesc('shopping_lists.created_at')
            ->orderByDesc('shopping_lists.id')
            ->get();

        $archive = ListQuery::visibleTo($user)
            ->where('shopping_lists.status', ListStatus::Done->value)
            ->with(['store', 'responsible', 'creator', 'members' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('shopping_lists.done_at')
            ->orderByDesc('shopping_lists.id')
            ->paginate((int) config('shopping.archive_per_page'), ['shopping_lists.*'], 'page', max(1, $page));

        return [
            'tab' => $tab === 'archive' ? 'archive' : 'open',
            'lists' => $open->map(fn (ShoppingList $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'status' => $l->status->value,
                'store' => $this->store($l->store),
                'responsible' => $this->person($this->responsible->effective($l)),
                'remindAt' => $this->iso($l->remind_at),
                'reminderPassed' => $this->passed($l),
                'itemsCount' => (int) $l->items_count,
                'boughtCount' => (int) $l->bought_count,
                'unavailableCount' => (int) $l->unavailable_count,
                'abilities' => ['copy' => $this->access->canCopy($user, $l)],
            ])->values()->all(),
            'archive' => [
                'data' => collect($archive->items())->map(fn (ShoppingList $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'doneAt' => $this->iso($l->done_at),
                    'storeName' => $l->store_name ?? $l->store?->name,
                    'abilities' => ['copy' => $this->access->canCopy($user, $l)],
                ])->values()->all(),
                'currentPage' => $archive->currentPage(),
                'lastPage' => $archive->lastPage(),
                'perPage' => $archive->perPage(),
                'total' => $archive->total(),
            ],
            'counts' => [
                'open' => $open->count(),
                'archive' => $archive->total(),
            ],
            // Only people who can create a list need the stores and the names of the people who may be responsible.
            'stores' => $canCreate ? $this->stores() : [],
            'responsibleCandidates' => $canCreate ? $this->responsibleCandidates() : [],
            'canCreate' => $canCreate,
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $user, ShoppingList $list): array
    {
        $list->loadMissing(['store', 'creator', 'responsible']);
        $abilities = $this->access->abilities($user, $list);
        $effective = $this->responsible->effective($list);
        $isCreator = $list->creator_id === $user->id;

        $items = ShoppingItem::query()->where('list_id', $list->id)
            ->with('catalogItem:id,image_path,image_version')
            ->orderBy('position')->orderBy('id')->get();

        $members = ShoppingListMember::query()->where('list_id', $list->id)->with('user:id,name,email')->get();
        $byRole = fn (MemberRole $role) => $members->where('role', $role)
            ->filter(fn (ShoppingListMember $m) => $m->user !== null)
            ->sortBy(fn (ShoppingListMember $m) => mb_strtolower($m->user->name))
            ->map(fn (ShoppingListMember $m) => $this->person($m->user))->values()->all();

        $props = [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'status' => $list->status->value,
                'store' => $this->store($list->store),
                'storeName' => $list->store?->name ?? $list->store_name,
                'creator' => ['id' => $list->creator->id, 'name' => $list->creator->name],
                'responsible' => ['id' => $effective->id, 'name' => $effective->name],
                'responsibleFallback' => $isCreator && $this->responsible->isFallback($list)
                    ? ['previousName' => $this->responsible->previousName($list)]
                    : null,
                'remindAt' => $this->iso($list->remind_at),
                'reminderPassed' => $this->passed($list),
                'reminderSent' => $list->reminder_enqueued_at !== null,
                'lockedAt' => $this->iso($list->locked_at),
                'doneAt' => $this->iso($list->done_at),
                'source' => $list->source_list_id === null ? null : ['id' => $list->source_list_id, 'kind' => $list->source_kind?->value],
            ],
            'items' => $items->map(fn (ShoppingItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'quantity' => (string) $i->quantity,
                'unitId' => $i->unit_id,
                'note' => $i->note,
                'state' => $i->state->value,
                'catalogItemId' => $i->catalog_item_id,
                'imageVersion' => $i->catalogItem?->image_path !== null ? $i->catalogItem->image_version : null,
            ])->values()->all(),
            'abilities' => $abilities,
            'members' => [
                'contributors' => $byRole(MemberRole::Contributor),
                'viewers' => $byRole(MemberRole::Viewer),
            ],
            'units' => $this->units(),
            'stores' => $this->stores(),
        ];

        if ($abilities['manageMembers'] || $abilities['changeResponsible']) {
            $props['candidates'] = [
                'responsible' => $this->responsibleCandidates(),
                'contributors' => $this->grantHolders(AccessLevel::Edit),
                'viewers' => $this->grantHolders(AccessLevel::View),
            ];
        }
        if ($abilities['setReminder']) {
            $props['responsiblePushEnabled'] = $this->preferences->enabled($effective, 'push')
                && PushSubscription::query()->where('user_id', $effective->id)->exists();
        }
        if ($abilities['editItems']) {
            $props['catalog'] = ShoppingCatalogItem::query()->orderBy('name_key')->orderBy('id')
                ->get(['id', 'name', 'default_unit_id', 'image_path', 'image_version'])
                ->map(fn (ShoppingCatalogItem $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'defaultUnitId' => $c->default_unit_id,
                    'imageVersion' => $c->image_path !== null ? $c->image_version : null,
                ])->values()->all();
        }

        return $props;
    }

    /** @return array{id: int, name: string, isChild: bool} */
    private function person(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'isChild' => $user->email === null];
    }

    /** @return array{id: int, name: string, logoVersion: ?int}|null */
    private function store(?ShoppingStore $store): ?array
    {
        return $store === null ? null : [
            'id' => $store->id,
            'name' => $store->name,
            'logoVersion' => $store->logo_path !== null ? $store->image_version : null,
        ];
    }

    /** @return list<array{id: int, name: string, logoVersion: ?int}> */
    private function stores(): array
    {
        return ShoppingStore::query()->orderBy('name_key')->orderBy('id')
            ->get(['id', 'name', 'logo_path', 'image_version'])
            ->map(fn (ShoppingStore $s) => $this->store($s))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function units(): array
    {
        return ShoppingUnit::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (ShoppingUnit $u) => [
                'id' => $u->id,
                'key' => $u->key,
                'names' => $u->key === null ? ['en' => $u->name_en, 'sk' => $u->name_sk, 'pl' => $u->name_pl] : null,
            ])->values()->all();
    }

    /** @return list<array{id: int, name: string, isChild: bool}> people who may be the responsible person */
    private function responsibleCandidates(): array
    {
        return collect($this->grantHolderModels(AccessLevel::Edit))
            ->filter(fn (User $u) => $this->responsible->eligible($u))
            ->map(fn (User $u) => $this->person($u))->values()->all();
    }

    /** @return list<array{id: int, name: string, isChild: bool}> */
    private function grantHolders(AccessLevel $level): array
    {
        return collect($this->grantHolderModels($level))->map(fn (User $u) => $this->person($u))->values()->all();
    }

    /** @return list<User> */
    private function grantHolderModels(AccessLevel $level): array
    {
        return User::query()
            ->whereIn('users.id', fn ($q) => $q->select('user_id')->from('module_permissions')
                ->where('module', Module::Shopping->value)->where('level', '>=', $level->value))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'email', 'sign_in_disabled'])->all();
    }

    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->setTimezone(config('family.timezone'))->toIso8601String();
    }

    private function passed(ShoppingList $list): bool
    {
        return $list->remind_at !== null && $list->remind_at->lte(now());
    }
}
