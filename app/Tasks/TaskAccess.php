<?php

namespace App\Tasks;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Task;
use App\Models\User;

/**
 * Record-level rights on top of the module grant. Every method assumes the task was loaded
 * through Task::visibleTo($user), so visibility is implied. There is no admin bypass.
 */
class TaskAccess
{
    public function __construct(private readonly AccessGate $gate) {}

    public function canEdit(User $user, Task $task): bool
    {
        return $this->hasEditGrant($user)
            && ($task->creator_id === $user->id || $task->assignee_id === $user->id);
    }

    public function canChangeStatus(User $user, Task $task): bool
    {
        return $this->hasEditGrant($user);
    }

    public function canDelete(User $user, Task $task): bool
    {
        return $this->hasEditGrant($user) && $task->creator_id === $user->id;
    }

    public function canChangeVisibility(User $user, Task $task): bool
    {
        return $this->canDelete($user, $task);
    }

    /** @return array{edit: bool, status: bool, delete: bool, visibility: bool} */
    public function abilities(User $user, Task $task): array
    {
        return [
            'edit' => $this->canEdit($user, $task),
            'status' => $this->canChangeStatus($user, $task),
            'delete' => $this->canDelete($user, $task),
            'visibility' => $this->canChangeVisibility($user, $task),
        ];
    }

    private function hasEditGrant(User $user): bool
    {
        return $this->gate->allows($user, Module::Todo, AccessLevel::Edit);
    }
}
