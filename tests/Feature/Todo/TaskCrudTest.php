<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create();
});

it('creates a fully specified task from the panel', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', [
        'title' => ' Pack bags ', 'notes' => 'Blue ones', 'due_date' => '2027-05-10', 'due_time' => '18:00',
        'status' => 'in_progress', 'assignee_id' => $this->child->id,
    ])->assertStatus(303)->assertSessionHas('toast.key', 'todo.toast.saved');

    $task = Task::query()->sole();
    expect($task->title)->toBe('Pack bags')
        ->and($task->notes)->toBe('Blue ones')
        ->and($task->due_date->format('Y-m-d'))->toBe('2027-05-10')
        ->and(substr((string) $task->due_time, 0, 5))->toBe('18:00')
        ->and($task->status->value)->toBe('in_progress')
        ->and($task->assignee_id)->toBe($this->child->id)
        ->and($task->creator_id)->toBe($this->parent->id)
        ->and($task->timezone)->toBe('Europe/Bratislava');
});

it('updates by creator and by assignee, but not by a third member', function () {
    $task = Task::factory()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->child->id, 'title' => 'Old']);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'By creator', 'notes' => 'n'])->assertStatus(303);
    expect($task->fresh()->title)->toBe('By creator')->and($task->fresh()->notes)->toBe('n');

    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}", ['title' => 'By assignee'])->assertStatus(303);
    expect($task->fresh()->title)->toBe('By assignee');

    $this->actingAs($this->grand)->patch("/todo/tasks/{$task->id}", ['title' => 'Nope'])->assertForbidden();
    expect($task->fresh()->title)->toBe('By assignee');
});

it('clears the time when the due date is cleared', function () {
    $task = Task::factory()->dueOn('2027-05-10', '18:00')->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => null, 'due_time' => null])->assertStatus(303);

    expect($task->fresh()->due_date)->toBeNull()->and($task->fresh()->due_time)->toBeNull();
});

it('validates dates, times and notes with i18n keys', function () {
    $post = fn (array $d) => $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 't'] + $d);

    $post(['due_date' => '2027-02-30'])->assertSessionHasErrors(['due_date' => 'todo.error.date_invalid']);
    $post(['due_time' => '10:00'])->assertSessionHasErrors(['due_time' => 'todo.error.time_invalid']);
    $post(['due_date' => '2027-05-10', 'due_time' => '25:00'])->assertSessionHasErrors(['due_time' => 'todo.error.time_invalid']);
    $post(['notes' => str_repeat('a', 5001)])->assertSessionHasErrors(['notes' => 'todo.error.notes_long']);
    expect(Task::count())->toBe(0);
});

it('ignores creator, timezone and completion fields in the payload', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', [
        'title' => 't', 'creator_id' => $this->child->id, 'timezone' => 'UTC', 'completed_at' => now()->toIso8601String(), 'visibility' => 'personal',
    ])->assertStatus(303);

    $task = Task::query()->sole();
    expect($task->creator_id)->toBe($this->parent->id)
        ->and($task->timezone)->toBe('Europe/Bratislava')
        ->and($task->completed_at)->toBeNull()
        ->and($task->visibility->value)->toBe('family');

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", [
        'title' => 'u', 'creator_id' => $this->child->id, 'timezone' => 'UTC', 'completed_at' => now()->toIso8601String(),
    ])->assertStatus(303);
    expect($task->fresh()->creator_id)->toBe($this->parent->id)->and($task->fresh()->timezone)->toBe('Europe/Bratislava')->and($task->fresh()->completed_at)->toBeNull();
});

it('lets only the creator delete', function () {
    $task = Task::factory()->selected([$this->child])->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->child->id]);

    $this->actingAs($this->child)->delete("/todo/tasks/{$task->id}")->assertForbidden();
    expect(Task::count())->toBe(1);

    $this->actingAs($this->parent)->delete("/todo/tasks/{$task->id}")->assertStatus(303)->assertSessionHas('toast.key', 'todo.toast.deleted');
    expect(Task::count())->toBe(0)->and(DB::table('task_members')->count())->toBe(0);
});

it('answers 404 for another member\'s Personal task and for a missing id alike', function () {
    $private = Task::factory()->personal()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    foreach ([$private->id, 999999] as $id) {
        $this->actingAs($this->child)->patch("/todo/tasks/{$id}", ['title' => 'x'])->assertNotFound();
        $this->actingAs($this->child)->patch("/todo/tasks/{$id}/status", ['status' => 'done'])->assertNotFound();
        $this->actingAs($this->child)->delete("/todo/tasks/{$id}")->assertNotFound();
    }
    expect($private->fresh()->title)->not->toBe('x')->and($private->fresh()->completed_at)->toBeNull();
});

it('exposes abilities and selectedTaskMissing on the board', function () {
    $task = Task::factory()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->child->id]);
    $private = Task::factory()->personal()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    $abilities = fn (User $u) => $this->actingAs($u)->get('/todo')->viewData('page')['props']['tasks'][0]['abilities'];

    expect($abilities($this->parent))->toBe(['edit' => true, 'status' => true, 'delete' => true, 'visibility' => true])
        ->and($abilities($this->child))->toBe(['edit' => true, 'status' => true, 'delete' => false, 'visibility' => false])
        ->and($abilities($this->grand))->toBe(['edit' => false, 'status' => true, 'delete' => false, 'visibility' => false]);

    $missing = fn (string $q) => $this->actingAs($this->child)->get("/todo?task={$q}")->viewData('page')['props']['selectedTaskMissing'];
    expect($missing((string) $private->id))->toBeTrue()
        ->and($missing('999999'))->toBeTrue()
        ->and($missing('new'))->toBeFalse()
        ->and($missing((string) $task->id))->toBeFalse();
});
