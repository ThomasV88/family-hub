<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('quick add creates a Family task for the creator and shows it on the board', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);

    $this->actingAs($parent)->post('/todo/tasks', ['title' => '  Buy milk '])->assertStatus(303);

    $task = Task::query()->sole();
    expect($task->title)->toBe('Buy milk')
        ->and($task->status->value)->toBe('todo')
        ->and($task->visibility->value)->toBe('family')
        ->and($task->creator_id)->toBe($parent->id)
        ->and($task->assignee_id)->toBe($parent->id)
        ->and($task->timezone)->toBe('Europe/Bratislava')
        ->and($task->due_date)->toBeNull();

    $this->actingAs($parent)->get('/todo')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Todo/Index', false)
        ->where('canEdit', true)
        ->where('timezone', 'Europe/Bratislava')
        ->has('tasks', 1)
        ->where('tasks.0.assigneeId', $parent->id)
        ->where('tasks.0.title', 'Buy milk')
        ->has('members', 1, fn (Assert $m) => $m->where('id', $parent->id)->where('name', 'Petra')));
});

it('flashes the new task id and toast key on store', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();

    $response = $this->actingAs($parent)->post('/todo/tasks', ['title' => 'x']);

    $response->assertSessionHas('flash_data.taskId', Task::query()->sole()->id);
});

it('applies record-level visibility with no admin bypass', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $grand = User::factory()->withPreset(Preset::Grandparent)->create();
    $admin = User::factory()->admin()->withGrant(Module::Todo, AccessLevel::Edit)->create();

    Task::factory()->create(['title' => 'Fam', 'creator_id' => $parent->id, 'assignee_id' => $parent->id]);
    Task::factory()->personal()->create(['title' => 'Priv', 'creator_id' => $parent->id, 'assignee_id' => $parent->id]);
    Task::factory()->selected([$child])->create(['title' => 'Sel', 'creator_id' => $parent->id, 'assignee_id' => $parent->id]);

    $titles = fn (User $u) => collect($this->actingAs($u)->get('/todo')->assertOk()->viewData('page')['props']['tasks'])->pluck('title')->sort()->values()->all();

    expect($titles($parent))->toBe(['Fam', 'Priv', 'Sel'])
        ->and($titles($child))->toBe(['Fam', 'Sel'])
        ->and($titles($grand))->toBe(['Fam'])
        ->and($titles($admin))->toBe(['Fam']);
});

it('gives view-only members the board without write access', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    $this->actingAs($guest)->get('/todo')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
    $this->actingAs($guest)->post('/todo/tasks', ['title' => 'x'])->assertForbidden();
    expect(Task::count())->toBe(0);
});

it('refuses members without To-do access and sends guests to login', function () {
    $admin = User::factory()->admin()->create();
    $none = User::factory()->create();

    $this->actingAs($admin)->get('/todo')->assertForbidden();
    $this->actingAs($none)->get('/todo')->assertForbidden();
    $this->actingAs($none)->post('/todo/tasks', ['title' => 'x'])->assertForbidden();
    auth()->logout();
    $this->get('/todo')->assertRedirect();
});

it('validates the title with i18n keys', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();

    $this->actingAs($parent)->post('/todo/tasks', ['title' => '   '])->assertSessionHasErrors(['title' => 'todo.error.title_required']);
    $this->actingAs($parent)->post('/todo/tasks', ['title' => str_repeat('a', 201)])->assertSessionHasErrors(['title' => 'todo.error.title_long']);
    expect(Task::count())->toBe(0);
});

it('ignores extra fields on quick add (no mass assignment)', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($parent)->post('/todo/tasks', [
        'title' => 'Mine', 'creator_id' => $other->id, 'visibility' => 'personal', 'completed_at' => now()->toIso8601String(),
    ])->assertStatus(303);

    $task = Task::query()->sole();
    expect($task->creator_id)->toBe($parent->id)
        ->and($task->assignee_id)->toBe($parent->id)
        ->and($task->status->value)->toBe('todo')
        ->and($task->completed_at)->toBeNull()
        ->and($task->visibility->value)->toBe('family');
});

it('lists as members only users with To-do view or better, exposing only id and name', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    User::factory()->admin()->create();
    User::factory()->create();
    User::factory()->withPreset(Preset::Guest)->create();

    $this->actingAs($parent)->get('/todo')->assertInertia(fn (Assert $p) => $p
        ->has('members', 2)
        ->has('members.0', fn (Assert $m) => $m->hasAll(['id', 'name'])));
});

it('hides skipped tasks and tasks completed more than 30 days ago', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $own = ['creator_id' => $parent->id, 'assignee_id' => $parent->id];

    Task::factory()->create($own + ['title' => 'open']);
    Task::factory()->done(now()->subDays(5))->create($own + ['title' => 'recent']);
    Task::factory()->done(now()->subDays(31))->create($own + ['title' => 'old']);
    Task::factory()->create($own + ['title' => 'skipped'])->forceFill(['skipped_at' => now()])->save();

    $titles = collect($this->actingAs($parent)->get('/todo')->viewData('page')['props']['tasks'])->pluck('title')->sort()->values()->all();
    expect($titles)->toBe(['open', 'recent']);
});

it('does not grow the query count with the number of tasks', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $own = ['creator_id' => $parent->id, 'assignee_id' => $parent->id];

    $count = function () use ($parent) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($parent)->get('/todo')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    Task::factory()->count(2)->selected([$child])->create($own);
    $count(); // warm
    $small = $count();
    Task::factory()->count(18)->selected([$child])->create($own);
    expect($count())->toBe($small);
});

it('enforces schema constraints', function () {
    $u = User::factory()->create();
    $base = fn (array $o = []) => array_merge([
        'title' => 't', 'creator_id' => $u->id, 'timezone' => 'Europe/Bratislava',
        'status' => 'todo', 'visibility' => 'family', 'created_at' => now(), 'updated_at' => now(),
    ], $o);
    $insert = fn (array $o = []) => fn () => DB::transaction(fn () => DB::table('tasks')->insert($base($o)));

    expect($insert(['status' => 'bogus']))->toThrow(QueryException::class);
    expect($insert(['visibility' => 'bogus']))->toThrow(QueryException::class);
    expect($insert(['due_time' => '10:00']))->toThrow(QueryException::class);
    expect($insert(['recurrence_rule' => 'FREQ=DAILY', 'recurrence_mode' => 'schedule']))->toThrow(QueryException::class);
    expect($insert(['due_date' => '2026-12-01', 'recurrence_rule' => 'FREQ=DAILY']))->toThrow(QueryException::class);

    $series = (string) Str::uuid();
    $insert(['series_id' => $series])();
    expect($insert(['series_id' => $series]))->toThrow(QueryException::class);
});
