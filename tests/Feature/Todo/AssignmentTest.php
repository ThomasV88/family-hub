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
});

it('accepts an assignee holding To-do view or better', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 't', 'assignee_id' => $guest->id])->assertStatus(303);

    expect(Task::query()->sole()->assignee_id)->toBe($guest->id);
});

it('rejects assignees without To-do access, and unknown ids', function () {
    $admin = User::factory()->admin()->create();
    $none = User::factory()->create();
    $onlyWishlist = User::factory()->withGrant(Module::Wishlist, AccessLevel::Edit)->create();

    foreach ([$admin->id, $none->id, $onlyWishlist->id, 987654] as $id) {
        $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 't', 'assignee_id' => $id])
            ->assertSessionHasErrors(['assignee_id' => 'todo.error.assignee']);
    }
    expect(Task::count())->toBe(0);
});

it('assigns the creator on quick add and Unassigned on explicit null', function () {
    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 'quick'])->assertStatus(303)->assertSessionHas('toast.key', 'todo.quick.added');
    $this->actingAs($this->parent)->post('/todo/tasks', ['title' => 'nobody', 'assignee_id' => null])->assertStatus(303);

    expect(Task::where('title', 'quick')->sole()->assignee_id)->toBe($this->parent->id)
        ->and(Task::where('title', 'nobody')->sole()->assignee_id)->toBeNull();
});

it('reassigns on update with the same rule', function () {
    $child = User::factory()->withPreset(Preset::Child)->create();
    $none = User::factory()->create();
    $task = Task::factory()->create(['creator_id' => $this->parent->id, 'assignee_id' => $this->parent->id]);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 't', 'assignee_id' => $child->id])->assertStatus(303);
    expect($task->fresh()->assignee_id)->toBe($child->id);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 't', 'assignee_id' => $none->id])
        ->assertSessionHasErrors(['assignee_id' => 'todo.error.assignee']);
    expect($task->fresh()->assignee_id)->toBe($child->id);
});
