<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->task = Task::factory()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);
});

it('lets a non-assignee with an edit grant complete and reopen a Family task', function () {
    $this->actingAs($this->child)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'done'])->assertStatus(303);

    $task = $this->task->fresh();
    expect($task->status->value)->toBe('done')->and($task->completed_at)->not->toBeNull()->and($task->completed_by)->toBe($this->child->id);

    $this->actingAs($this->child)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'todo'])->assertStatus(303);

    $task = $this->task->fresh();
    expect($task->status->value)->toBe('todo')->and($task->completed_at)->toBeNull()->and($task->completed_by)->toBeNull();
});

it('refuses view-only members and bogus statuses', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    $this->actingAs($guest)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'done'])->assertForbidden();
    $this->actingAs($this->child)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'bogus'])->assertSessionHasErrors('status');
    $this->actingAs($this->child)->patch("/todo/tasks/{$this->task->id}/status", [])->assertSessionHasErrors('status');
    expect($this->task->fresh()->status->value)->toBe('todo');
});

it('is idempotent when repeating done', function () {
    $this->actingAs($this->parent)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'done']);
    $first = $this->task->fresh()->completed_at;

    $this->travel(5)->minutes();
    $this->actingAs($this->child)->patch("/todo/tasks/{$this->task->id}/status", ['status' => 'done'])->assertStatus(303);

    $task = $this->task->fresh();
    expect($task->completed_at->equalTo($first))->toBeTrue()->and($task->completed_by)->toBe($this->parent->id);
});

it('moves a task to in progress through the panel update too', function () {
    $this->actingAs($this->parent)->patch("/todo/tasks/{$this->task->id}", ['title' => 't', 'status' => 'done'])->assertStatus(303);
    expect($this->task->fresh()->completed_at)->not->toBeNull();

    $this->actingAs($this->parent)->patch("/todo/tasks/{$this->task->id}", ['title' => 't', 'status' => 'in_progress'])->assertStatus(303);
    expect($this->task->fresh()->status->value)->toBe('in_progress')->and($this->task->fresh()->completed_at)->toBeNull();
});
