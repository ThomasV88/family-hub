<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrenceMode;
use App\Tasks\TaskCompletionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

const DAILY = 'FREQ=DAILY;INTERVAL=1';

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->service = app(TaskCompletionService::class);
});

afterEach(fn () => Carbon::setTestNow());

function recurring(string $rule, string $mode, string $due, ?string $time = null, array $extra = []): Task
{
    return Task::factory()->recurring($rule, $mode)->dueOn($due, $time)->create($extra + [
        'creator_id' => test()->parent->id,
        'assignee_id' => test()->parent->id,
    ]);
}

function completeAt(Task $task, string $now): ?Task
{
    Carbon::setTestNow($now);

    return test()->service->complete($task, test()->parent);
}

it('skips missed days of a schedule series instead of piling up', function () {
    $next = completeAt(recurring(DAILY, 'schedule', '2027-05-10'), '2027-05-13 12:00:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-05-14');
});

it('counts a completion series from the completion day', function () {
    $next = completeAt(recurring('FREQ=DAILY;INTERVAL=3', 'completion', '2027-05-10'), '2027-05-12 12:00:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-05-15');
});

it('follows chosen weekdays', function () {
    // 2027-05-10 is a Monday
    $next = completeAt(recurring('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE', 'schedule', '2027-05-10'), '2027-05-10 09:00:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-05-12');
});

it('clamps day 31 to month ends without drifting', function () {
    $rule = 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1';
    $task = recurring($rule, 'schedule', '2027-01-31');
    $dates = [];
    foreach (['2027-01-31 10:00', '2027-02-28 10:00', '2027-03-31 10:00'] as $now) {
        $task = completeAt($task, $now.':00 Europe/Bratislava');
        $dates[] = $task->due_date->format('Y-m-d');
    }
    expect($dates)->toBe(['2027-02-28', '2027-03-31', '2027-04-30']);
});

it('repeats on the last day of the month', function () {
    $next = completeAt(recurring('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1', 'schedule', '2027-01-31'), '2027-01-31 10:00:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-02-28');
});

it('keeps every 3 months anchored on the original day', function () {
    $task = recurring('FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=28,29,30,31;BYSETPOS=-1', 'schedule', '2027-01-31');
    $first = completeAt($task, '2027-01-31 10:00:00 Europe/Bratislava');
    $second = completeAt($first, '2027-04-30 10:00:00 Europe/Bratislava');
    expect([$first->due_date->format('Y-m-d'), $second->due_date->format('Y-m-d')])->toBe(['2027-04-30', '2027-07-31']);
});

it('counts a monthly completion series from the completion month', function () {
    $next = completeAt(recurring('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1', 'completion', '2027-01-31'), '2027-01-31 10:00:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-02-28');
});

it('keeps the local time and schedules the reminder for a timed task', function () {
    $next = completeAt(recurring(DAILY, 'schedule', '2027-03-27', '08:00'), '2027-03-27 07:30:00 Europe/Bratislava');
    expect($next->due_date->format('Y-m-d'))->toBe('2027-03-28')
        ->and(substr((string) $next->due_time, 0, 5))->toBe('08:00')
        ->and($next->fresh()->remind_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-28 06:00');
});

it('copies the task into the next occurrence and keeps the completed one in history', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10', null, ['notes' => 'Bring bags', 'visibility' => 'selected', 'remind_before_minutes' => 1440]);
    $task->members()->sync([$this->parent->id, $this->child->id]);

    $next = completeAt($task, '2027-05-10 10:00:00 Europe/Bratislava');

    expect($next->title)->toBe($task->title)->and($next->notes)->toBe('Bring bags')
        ->and($next->visibility->value)->toBe('selected')->and($next->series_id)->toBe($task->series_id)
        ->and($next->status->value)->toBe('todo')->and($next->remind_before_minutes)->toBe(1440)
        ->and($next->assignee_id)->toBe($this->parent->id)->and($next->creator_id)->toBe($this->parent->id)
        ->and($next->members()->pluck('users.id')->sort()->values()->all())->toBe(collect([$this->parent->id, $this->child->id])->sort()->values()->all());

    $done = $task->fresh();
    expect($done->status->value)->toBe('done')->and($done->completed_at)->not->toBeNull()->and($done->completed_by)->toBe($this->parent->id);
    expect(Task::where('series_id', $task->series_id)->whereNull('completed_at')->whereNull('skipped_at')->count())->toBe(1);
});

it('creates one next occurrence when completed twice', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10');
    $stale = Task::find($task->id);
    completeAt($task, '2027-05-10 10:00:00 Europe/Bratislava');

    expect($this->service->complete($stale, $this->parent))->toBeNull();
    expect(Task::where('series_id', $task->series_id)->count())->toBe(2);
});

it('treats a concurrent insert hitting the one-open-per-series index as a no-op', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10');
    $engine = new class($task) implements RecurrenceEngine
    {
        public function __construct(private Task $task) {}

        public function next(string $rrule, CarbonImmutable $anchorLocal, RecurrenceMode $mode, ?CarbonImmutable $completedAtLocal, string $timezone, CarbonImmutable $now): ?CarbonImmutable
        {
            // A racing request opens the next occurrence while ours is being computed.
            Task::factory()->recurring(DAILY)->dueOn('2027-05-11')->create([
                'series_id' => $this->task->series_id, 'creator_id' => $this->task->creator_id,
            ]);

            return CarbonImmutable::parse('2027-05-11', $timezone);
        }
    };

    $result = (new TaskCompletionService($engine))->complete($task, $this->parent);

    expect($result)->toBeNull()
        ->and(Task::where('series_id', $task->series_id)->whereNull('completed_at')->count())->toBe(1);
});

it('skips an occurrence, opens the next and records it in history', function () {
    Carbon::setTestNow('2027-05-10 09:00:00 Europe/Bratislava');
    $task = recurring(DAILY, 'schedule', '2027-05-10');

    $this->actingAs($this->child)->post("/todo/tasks/{$task->id}/skip")->assertStatus(303)
        ->assertSessionHas('flash_data.nextDue', '2027-05-11')
        ->assertSessionHas('flash_data.toast', 'todo.toast.skipped');

    $skipped = $task->fresh();
    expect($skipped->skipped_at)->not->toBeNull()->and($skipped->status->value)->toBe('todo');
    $open = Task::where('series_id', $task->series_id)->whereNull('skipped_at')->whereNull('completed_at')->get();
    expect($open)->toHaveCount(1)->and($open->first()->due_date->format('Y-m-d'))->toBe('2027-05-11');
});

it('refuses skipping a non-recurring task, view-only members and hides invisible tasks', function () {
    $plain = Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $this->parent->id]);
    $this->actingAs($this->parent)->post("/todo/tasks/{$plain->id}/skip")->assertStatus(422);

    $series = recurring(DAILY, 'schedule', '2027-05-10');
    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $this->actingAs($guest)->post("/todo/tasks/{$series->id}/skip")->assertForbidden();

    $personal = recurring(DAILY, 'schedule', '2027-05-10', null, ['visibility' => 'personal']);
    $this->actingAs($this->child)->post("/todo/tasks/{$personal->id}/skip")->assertNotFound();
});

it('does not allow undoing a completed recurring occurrence', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10');
    completeAt($task, '2027-05-10 10:00:00 Europe/Bratislava');

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}/status", ['status' => 'todo'])->assertStatus(409);
    expect($task->fresh()->status->value)->toBe('done');

    $this->actingAs($this->parent)->get('/todo')->assertInertia(fn (Assert $p) => $p
        ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('id', $task->id)['abilities']['status'] === false));
});

it('completes through the status endpoint and flashes the next due date', function () {
    Carbon::setTestNow('2027-05-10 09:00:00 Europe/Bratislava');
    $task = recurring(DAILY, 'schedule', '2027-05-10', '18:30');

    $this->actingAs($this->child)->patch("/todo/tasks/{$task->id}/status", ['status' => 'done'])->assertStatus(303)
        ->assertSessionHas('flash_data.nextDue', '2027-05-11 18:30')
        ->assertSessionHas('flash_data.toast', 'todo.toast.done_next');
    expect(Task::where('series_id', $task->series_id)->count())->toBe(2);

    // moving to in progress is a plain status change
    $open = Task::where('series_id', $task->series_id)->whereNull('completed_at')->first();
    $this->actingAs($this->child)->patch("/todo/tasks/{$open->id}/status", ['status' => 'in_progress'])->assertStatus(303);
    expect($open->fresh()->status->value)->toBe('in_progress')->and(Task::where('series_id', $task->series_id)->count())->toBe(2);
});

it('completes a recurring task through the panel update once', function () {
    Carbon::setTestNow('2027-05-10 09:00:00 Europe/Bratislava');
    $task = recurring(DAILY, 'schedule', '2027-05-10');

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", [
        'title' => $task->title, 'due_date' => '2027-05-10', 'status' => 'done',
        'recurrence' => ['kind' => 'daily', 'interval' => 1], 'recurrence_mode' => 'schedule',
    ])->assertStatus(303)->assertSessionHas('flash_data.nextDue', '2027-05-11');

    expect(Task::where('series_id', $task->series_id)->count())->toBe(2)
        ->and($task->fresh()->status->value)->toBe('done');
});

it('lists the series history newest first for the open occurrence', function () {
    Carbon::setTestNow('2027-05-10 09:00:00 Europe/Bratislava');
    $first = recurring(DAILY, 'schedule', '2027-05-10');
    $second = completeAt($first, '2027-05-10 09:00:00 Europe/Bratislava');
    Carbon::setTestNow('2027-05-11 09:00:00 Europe/Bratislava');
    $third = $this->service->skip($second, $this->parent);
    Carbon::setTestNow('2027-05-12 09:00:00 Europe/Bratislava');

    $this->actingAs($this->child)->get("/todo?task={$third->id}")->assertInertia(fn (Assert $p) => $p
        ->where('history.0.kind', 'skipped')->where('history.1.kind', 'completed')->has('history', 2));
    $this->actingAs($this->child)->get('/todo')->assertInertia(fn (Assert $p) => $p->where('history', null));
});

it('hides personal rows of a series from another viewer in the history', function () {
    $first = recurring(DAILY, 'schedule', '2027-05-10');
    $second = completeAt($first, '2027-05-10 09:00:00 Europe/Bratislava');
    Task::whereKey($first->id)->update(['visibility' => 'personal']);

    $this->actingAs($this->child)->get("/todo?task={$second->id}")->assertInertia(fn (Assert $p) => $p->has('history', 0));
});

it('keeps closed occurrences immutable: update, status, skip and delete answer 409', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10');
    completeAt($task, '2027-05-10 10:00:00 Europe/Bratislava');
    $before = $task->fresh();
    $open = Task::where('series_id', $task->series_id)->whereNull('completed_at')->first();

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'Rewritten', 'recurrence' => null])->assertStatus(409);
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}/status", ['status' => 'todo'])->assertStatus(409);
    $this->actingAs($this->parent)->post("/todo/tasks/{$task->id}/skip")->assertStatus(409);
    $this->actingAs($this->parent)->delete("/todo/tasks/{$task->id}")->assertStatus(409);

    $after = $task->fresh();
    expect($after->title)->toBe($before->title)->and($after->recurrence_rule)->toBe($before->recurrence_rule);
    expect(Task::where('series_id', $task->series_id)->whereNull('completed_at')->whereNull('skipped_at')->count())->toBe(1);
    expect($open)->not->toBeNull();
});

it('still treats a closed row as history after its rule was cleared', function () {
    $task = recurring(DAILY, 'schedule', '2027-05-10');
    completeAt($task, '2027-05-10 10:00:00 Europe/Bratislava');
    $task->forceFill(['recurrence_rule' => null, 'recurrence_mode' => null])->save();

    expect($task->fresh()->isClosedOccurrence())->toBeTrue();
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}/status", ['status' => 'todo'])->assertStatus(409);
});

it('continues a daily schedule series that is overdue by three years', function () {
    $task = recurring(DAILY, 'schedule', '2024-05-10', '18:30');

    $next = completeAt($task, '2027-05-10 09:00:00 Europe/Bratislava');

    expect($next)->not->toBeNull()->and($next->due_date->format('Y-m-d'))->toBe('2027-05-10')
        ->and(substr((string) $next->due_time, 0, 5))->toBe('18:30');
});

it('keeps a weekly every-2-weeks rule aligned when overdue for years', function () {
    $task = recurring('FREQ=WEEKLY;INTERVAL=2', 'schedule', '2024-05-06');

    $next = completeAt($task, '2027-05-11 09:00:00 Europe/Bratislava');

    // 2024-05-06 + n*14 days: 2027-05-10 is +1099 days (odd week), so the next aligned Monday is 2027-05-17.
    expect($next->due_date->format('Y-m-d'))->toBe('2027-05-17');
});
