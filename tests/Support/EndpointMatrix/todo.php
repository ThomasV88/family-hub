<?php

use App\Models\Task;

// A fresh Family task created by and assigned to the parent, per call (destroy removes it).
$familyTask = fn (array $users) => ['task' => Task::factory()->create([
    'creator_id' => $users['parent']->id,
    'assignee_id' => $users['parent']->id,
])->id];

return [
    'todo.index' => [
        'method' => 'GET',
        'uri' => '/todo',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403,
        ],
    ],
    'todo.tasks.store' => [
        'method' => 'POST',
        'uri' => '/todo/tasks',
        'data' => ['title' => 'Matrix task'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'todo.tasks.update' => [
        'method' => 'PATCH',
        'uri' => '/todo/tasks/{task}',
        'params' => $familyTask,
        'data' => ['title' => 'Changed'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'todo.tasks.status' => [
        'method' => 'PATCH',
        'uri' => '/todo/tasks/{task}/status',
        'params' => $familyTask,
        'data' => ['status' => 'done'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'todo.tasks.destroy' => [
        'method' => 'DELETE',
        'uri' => '/todo/tasks/{task}',
        'params' => $familyTask,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
