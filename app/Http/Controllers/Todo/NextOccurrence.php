<?php

namespace App\Http\Controllers\Todo;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;

/** Redirect back with the next due date (family-local) and the matching toast when an occurrence was advanced. */
final class NextOccurrence
{
    public static function redirect(Task $task, string $toastKey = 'todo.toast.done_next'): RedirectResponse
    {
        $next = $task->relationLoaded('nextOccurrence') ? $task->getRelation('nextOccurrence') : null;
        $response = back(303);
        if (! $next instanceof Task) {
            return $response;
        }

        $time = $next->due_time === null ? null : substr((string) $next->due_time, 0, 5);
        $due = $next->due_date->format('Y-m-d').($time !== null ? ' '.$time : '');

        return $response->with('flash_data', ['nextDue' => $due])->with('toast', ['key' => $toastKey]);
    }
}
