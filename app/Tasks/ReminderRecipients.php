<?php

namespace App\Tasks;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Task;
use App\Models\User;

/** Who gets a task reminder: the assignee (creator if unassigned; only the creator for Personal), if still allowed. */
class ReminderRecipients
{
    public function __construct(private readonly AccessGate $gate) {}

    /** @return list<User> */
    public function for(Task $task): array
    {
        $id = $task->visibility === TaskVisibility::Personal
            ? $task->creator_id
            : ($task->assignee_id ?? $task->creator_id);

        $user = User::query()->find($id);

        return $user !== null && $this->canSee($user, $task) ? [$user] : [];
    }

    public function canSee(User $user, Task $task): bool
    {
        // The gate caches levels per instance; a long-lived process must not trust a stale grant.
        $this->gate->forget($user);

        return $this->gate->allows($user, Module::Todo, AccessLevel::View)
            && Task::query()->visibleTo($user)->whereKey($task->id)->exists();
    }
}
