<?php

use App\Access\Module;
use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function dueSoonFor(User $user)
{
    return test()->actingAs($user)->get('/')->assertOk()->viewData('page')['props']['dueSoon'];
}

it('shows 5 items overdue first and counts the rest', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $today = now(config('family.timezone'));
    foreach ([6, 3] as $ago) {
        Task::factory()->create(['title' => "late{$ago}", 'creator_id' => $parent->id, 'assignee_id' => $parent->id])
            ->forceFill(['due_date' => $today->copy()->subDays($ago)->toDateString()])->save();
    }
    foreach ([0, 1, 2, 3, 4] as $ahead) {
        Task::factory()->dueOn($today->copy()->addDays($ahead)->toDateString())
            ->create(['title' => "soon{$ahead}", 'creator_id' => $parent->id, 'assignee_id' => $parent->id]);
    }

    $due = dueSoonFor($parent);

    expect($due['items'])->toHaveCount(5)
        ->and(collect($due['items'])->pluck('title')->all())->toBe(['late6', 'late3', 'soon0', 'soon1', 'soon2'])
        ->and($due['more'])->toBe(2)
        ->and($due['hasAnyTasks'])->toBeTrue()
        ->and($due['timezone'])->toBe('Europe/Bratislava');
});

it('excludes done, undated and foreign-assigned tasks', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $d = now(config('family.timezone'))->toDateString();
    $own = ['creator_id' => $parent->id, 'assignee_id' => $parent->id];

    Task::factory()->dueOn($d)->done()->create($own + ['title' => 'done']);
    Task::factory()->create($own + ['title' => 'undated']);
    Task::factory()->dueOn($d)->create(['creator_id' => $parent->id, 'assignee_id' => $other->id, 'title' => 'theirs']);
    Task::factory()->dueOn($d)->create($own + ['title' => 'mine']);

    $due = dueSoonFor($parent);

    expect(collect($due['items'])->pluck('title')->all())->toBe(['mine'])->and($due['more'])->toBe(0);
});

it('never shows another member Personal task, even when assigned to me', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $d = now(config('family.timezone'))->toDateString();

    Task::factory()->personal()->dueOn($d)->create(['title' => 'secret', 'creator_id' => $child->id, 'assignee_id' => $parent->id]);

    $due = dueSoonFor($parent);

    expect($due['items'])->toBe([])->and($due['hasAnyTasks'])->toBeFalse();
});

it('does not leak a Selected task to an assignee who is not a member', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $grand = User::factory()->withPreset(Preset::Grandparent)->create();
    $d = now(config('family.timezone'))->toDateString();

    Task::factory()->selected([$child])->dueOn($d)->create(['title' => 'sel', 'creator_id' => $child->id, 'assignee_id' => $parent->id]);
    expect(dueSoonFor($parent)['items'])->toBe([]);

    Task::query()->sole()->members()->attach($parent->id);
    expect(collect(dueSoonFor($parent)['items'])->pluck('title')->all())->toBe(['sel']);
});

it('is null without To-do access and present for view-only', function () {
    $admin = User::factory()->admin()->create();
    $none = User::factory()->create();
    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $d = now(config('family.timezone'))->toDateString();
    Task::factory()->dueOn($d)->create(['title' => 'fam', 'creator_id' => $guest->id, 'assignee_id' => $guest->id]);

    expect(dueSoonFor($admin))->toBeNull()
        ->and(dueSoonFor($none))->toBeNull()
        ->and(dueSoonFor($guest)['items'])->toHaveCount(1);
});

it('items carry exactly id, title, dueDate, dueTime, visibility', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    Task::factory()->dueOn(now(config('family.timezone'))->toDateString(), '23:30')
        ->create(['notes' => 'private notes', 'creator_id' => $parent->id, 'assignee_id' => $parent->id]);

    $this->actingAs($parent)->get('/')->assertInertia(fn (Assert $p) => $p
        ->has('dueSoon.items.0', fn (Assert $i) => $i->hasAll(['id', 'title', 'dueDate', 'dueTime', 'visibility'])->where('dueTime', '23:30')));
});
