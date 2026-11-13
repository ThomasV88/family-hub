<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->grandparent = User::factory()->withPreset(Preset::Grandparent)->create();
});

it('forces a Personal task to its creator and stores no members', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 'p', 'visibility' => 'personal', 'assignee_id' => $this->child->id])->assertStatus(303);

    $task = Task::query()->sole();
    expect($task->assignee_id)->toBe($this->parent->id)
        ->and($task->visibility->value)->toBe('personal')
        ->and($task->members()->count())->toBe(0);
});

it('stores Selected members plus creator and assignee', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', [
        'title' => 's', 'visibility' => 'selected', 'member_ids' => [$this->child->id], 'assignee_id' => $this->child->id,
    ])->assertStatus(303);

    expect(Task::query()->sole()->members()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->parent->id, $this->child->id])->sort()->values()->all());
});

it('rejects a Selected assignee outside the chosen members, empty lists and members without access', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', [
        'title' => 's', 'visibility' => 'selected', 'member_ids' => [$this->child->id], 'assignee_id' => $this->grandparent->id,
    ])->assertSessionHasErrors(['assignee_id' => 'todo.error.assignee']);

    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 's', 'visibility' => 'selected', 'member_ids' => []])
        ->assertSessionHasErrors(['member_ids' => 'todo.error.selected_none']);
    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 's', 'visibility' => 'selected'])
        ->assertSessionHasErrors(['member_ids' => 'todo.error.selected_none']);

    $none = User::factory()->create();
    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 's', 'visibility' => 'selected', 'member_ids' => [$none->id]])
        ->assertSessionHasErrors(['member_ids.0' => 'todo.error.assignee']);

    expect(Task::count())->toBe(0);
});

it('moves between visibilities on update', function () {
    $task = Task::factory()->selected([$this->child])->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->child->id]);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 't', 'visibility' => 'family'])->assertStatus(303);
    expect($task->fresh()->visibility->value)->toBe('family')->and($task->members()->count())->toBe(0);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 't', 'visibility' => 'personal'])->assertStatus(303);
    expect($task->fresh()->assignee_id)->toBe($this->parent->id);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 't', 'visibility' => 'selected', 'member_ids' => [$this->grandparent->id], 'assignee_id' => $this->grandparent->id])->assertStatus(303);
    expect($task->members()->count())->toBe(2);
});

it('lets a non-creator assignee edit but not change visibility', function () {
    $task = Task::factory()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->child->id, 'title' => 'old']);

    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}", ['title' => 'new', 'assignee_id' => $this->child->id])->assertStatus(303);
    expect($task->fresh()->title)->toBe('new');

    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'visibility' => 'personal'])->assertForbidden();
    expect($task->fresh()->visibility->value)->toBe('family')->and($task->fresh()->title)->toBe('new');
});

it('hides another member\'s Personal task with 404 everywhere', function () {
    $task = Task::factory()->personal()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}/status", ['status' => 'done'])->assertNotFound();
    $this->actingAs($this->child)->delete("/todo/tasks/{$task->id}")->assertNotFound();

    $this->actingAs($this->child)->get('/todo')->assertInertia(fn ($page) => $page->has('tasks', 0));
    $this->actingAs($this->child)->get("/todo?task={$task->id}")->assertInertia(fn ($page) => $page->where('selectedTaskMissing', true));
    expect(Task::count())->toBe(1);
});

it('gives an admin with a To-do grant no access to a Personal task and an admin without grants 403', function () {
    $task = Task::factory()->personal()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);
    $admin = User::factory()->admin()->withGrant(Module::Todo, AccessLevel::Edit)->create();

    $this->actingAs($admin)->patch("/todo/tasks/{$task->id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($admin)->delete("/todo/tasks/{$task->id}")->assertNotFound();
    $this->actingAs($admin)->get('/todo')->assertInertia(fn ($page) => $page->has('tasks', 0));
    $this->actingAs($admin)->get("/todo?task={$task->id}")->assertInertia(fn ($page) => $page->where('selectedTaskMissing', true));

    $bare = User::factory()->admin()->create();
    $this->actingAs($bare)->get('/todo')->assertForbidden();
    $this->actingAs($bare)->patch("/todo/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($bare)->delete("/todo/tasks/{$task->id}")->assertForbidden();
    $this->actingAs($bare)->patch("/todo/tasks/{$task->id}/status", ['status' => 'done'])->assertForbidden();
});

it('shows a Selected task to its members and creator only', function () {
    $task = Task::factory()->selected([$this->child])->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    $this->actingAs($this->child)->get('/todo')->assertInertia(fn ($page) => $page->has('tasks', 1));
    $this->actingAs($this->parent)->get('/todo')->assertInertia(fn ($page) => $page->has('tasks', 1));
    $this->actingAs($this->grandparent)->get('/todo')->assertInertia(fn ($page) => $page->has('tasks', 0));
    $this->actingAs($this->grandparent)->patch("/todo/tasks/{$task->id}", ['title' => 'x'])->assertNotFound();
});

it('ignores mass-assigned protected columns', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', [
        'title' => 'm', 'creator_id' => $this->child->id, 'series_id' => 99, 'completed_at' => now()->toDateTimeString(), 'remind_at' => now()->toDateTimeString(),
    ])->assertStatus(303);

    $task = Task::query()->sole();
    expect($task->creator_id)->toBe($this->parent->id)->and($task->series_id)->toBeNull()
        ->and($task->completed_at)->toBeNull()->and($task->remind_at)->toBeNull();
});
