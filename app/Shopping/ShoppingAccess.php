<?php

namespace App\Shopping;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\User;

/**
 * Record-level rights on top of the Shopping module grant. There is no administrator bypass.
 * The can* methods answer "may this person do it now" (they include the list status) and feed abilities() for the UI.
 * The may* and isResponsible methods ignore the status; services use them so that a wrong role is a 403 and a wrong
 * status is a 409.
 */
final class ShoppingAccess
{
    public const CREATOR = 'creator';

    public const RESPONSIBLE = 'responsible';

    public const CONTRIBUTOR = 'contributor';

    public const VIEWER = 'viewer';

    public function __construct(private readonly AccessGate $gate, private readonly ResponsiblePerson $responsible) {}

    /** creator|responsible|contributor|viewer, or null when the person has no place on the list. */
    public function role(User $user, ShoppingList $list): ?string
    {
        $effective = $this->responsible->effective($list);

        if ($effective->id === $user->id) {
            return $list->creator_id === $user->id ? self::CREATOR : self::RESPONSIBLE;
        }
        if ($list->creator_id === $user->id) {
            return self::CREATOR;
        }

        $member = ShoppingListMember::query()->where('list_id', $list->id)->where('user_id', $user->id)->first();
        if ($member !== null) {
            // A member without Shopping edit acts as a viewer whatever role they were given.
            return $member->role === MemberRole::Contributor && $this->hasEdit($user) ? self::CONTRIBUTOR : self::VIEWER;
        }

        // The stored responsible person who lost the right to act still sees the list, read-only.
        return $list->responsible_id === $user->id ? self::VIEWER : null;
    }

    public function isResponsible(User $user, ShoppingList $list): bool
    {
        return $this->hasEdit($user) && $this->responsible->effective($list)->id === $user->id;
    }

    public function canView(User $user, ShoppingList $list): bool
    {
        return $this->gate->allows($user, Module::Shopping, AccessLevel::View) && $this->role($user, $list) !== null;
    }

    /** Creator or effective responsible with Shopping edit (list header, people, delete), whatever the status. */
    public function mayManage(User $user, ShoppingList $list): bool
    {
        return $this->hasEdit($user) && in_array($this->role($user, $list), [self::CREATOR, self::RESPONSIBLE], true);
    }

    /** Creator, responsible or contributor with Shopping edit, whatever the status. */
    public function mayEditItems(User $user, ShoppingList $list): bool
    {
        return $this->hasEdit($user)
            && in_array($this->role($user, $list), [self::CREATOR, self::RESPONSIBLE, self::CONTRIBUTOR], true);
    }

    public function canEditSettings(User $user, ShoppingList $list): bool
    {
        return $this->isOpen($list) && $this->mayManage($user, $list);
    }

    /** Open: creator or responsible. Locked: the effective responsible only. Done: nobody. */
    public function canChangeResponsible(User $user, ShoppingList $list): bool
    {
        return match ($list->status) {
            ListStatus::Open => $this->mayManage($user, $list),
            ListStatus::Locked => $this->isResponsible($user, $list),
            default => false,
        };
    }

    public function canSetReminder(User $user, ShoppingList $list): bool
    {
        return $this->canChangeResponsible($user, $list);
    }

    public function canManageMembers(User $user, ShoppingList $list): bool
    {
        return $this->canEditSettings($user, $list);
    }

    public function canEditItems(User $user, ShoppingList $list): bool
    {
        return $this->isOpen($list) && $this->mayEditItems($user, $list);
    }

    public function canLock(User $user, ShoppingList $list): bool
    {
        return $this->isOpen($list) && $this->isResponsible($user, $list);
    }

    public function canUnlock(User $user, ShoppingList $list): bool
    {
        return $list->status === ListStatus::Locked && $this->isResponsible($user, $list);
    }

    public function canMark(User $user, ShoppingList $list): bool
    {
        return $this->canUnlock($user, $list);
    }

    public function canFinish(User $user, ShoppingList $list): bool
    {
        return $this->canUnlock($user, $list);
    }

    /** Anyone who can see the list and holds Shopping edit, in every status. */
    public function canCopy(User $user, ShoppingList $list): bool
    {
        return $this->hasEdit($user) && $this->role($user, $list) !== null;
    }

    /** Creator, responsible and contributors with Shopping edit, in every status; never viewers. */
    public function canMail(User $user, ShoppingList $list): bool
    {
        return $this->mayEditItems($user, $list);
    }

    /** Only while open: archived and locked lists cannot be deleted. */
    public function canDelete(User $user, ShoppingList $list): bool
    {
        return $this->isOpen($list) && $this->mayManage($user, $list);
    }

    public function canManageCatalog(User $user): bool
    {
        return $this->hasEdit($user);
    }

    /** @return array<string, bool> */
    public function abilities(User $user, ShoppingList $list): array
    {
        return [
            'editSettings' => $this->canEditSettings($user, $list),
            'changeResponsible' => $this->canChangeResponsible($user, $list),
            'setReminder' => $this->canSetReminder($user, $list),
            'manageMembers' => $this->canManageMembers($user, $list),
            'editItems' => $this->canEditItems($user, $list),
            'lock' => $this->canLock($user, $list),
            'unlock' => $this->canUnlock($user, $list),
            'mark' => $this->canMark($user, $list),
            'finish' => $this->canFinish($user, $list),
            'copy' => $this->canCopy($user, $list),
            'mail' => $this->canMail($user, $list),
            'delete' => $this->canDelete($user, $list),
            'manageCatalog' => $this->canManageCatalog($user),
        ];
    }

    private function isOpen(ShoppingList $list): bool
    {
        return $list->status === ListStatus::Open;
    }

    private function hasEdit(User $user): bool
    {
        return $this->gate->allows($user, Module::Shopping, AccessLevel::Edit);
    }
}
