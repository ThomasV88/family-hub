<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
});

function postTask(array $data)
{
    return test()->actingAs(test()->parent)->post('/todo/tasks', ['title' => 'Repeat'] + $data);
}

function lastRule(): ?string
{
    return Task::query()->latest('id')->first()->recurrence_rule;
}

it('needs a due date to repeat', function () {
    postTask(['recurrence' => ['kind' => 'daily', 'interval' => 1], 'recurrence_mode' => 'schedule'])
        ->assertSessionHasErrors(['recurrence' => 'todo.repeat.need_date']);
});

it('rejects weekly without weekdays and bad intervals', function () {
    $base = ['due_date' => '2027-05-10', 'recurrence_mode' => 'schedule'];
    postTask($base + ['recurrence' => ['kind' => 'weekly', 'interval' => 1, 'weekdays' => []]])
        ->assertSessionHasErrors(['recurrence.weekdays' => 'todo.error.weekdays']);
    foreach ([0, 100] as $interval) {
        postTask($base + ['recurrence' => ['kind' => 'custom', 'interval' => $interval, 'unit' => 'day']])
            ->assertSessionHasErrors(['recurrence.interval' => 'todo.error.interval']);
    }
    expect(Task::count())->toBe(0);
});

it('needs a mode with a pattern', function () {
    postTask(['due_date' => '2027-05-10', 'recurrence' => ['kind' => 'daily', 'interval' => 1]])->assertSessionHasErrors('recurrence_mode');
});

it('ignores raw rrule keys', function () {
    postTask(['due_date' => '2027-05-10', 'rrule' => 'FREQ=SECONDLY', 'recurrence_rule' => 'FREQ=SECONDLY', 'recurrence_mode' => 'schedule'])->assertStatus(303);
    $task = Task::first();
    expect($task->recurrence_rule)->toBeNull()->and($task->recurrence_mode)->toBeNull();
});

it('stores a daily and weekly pattern with a series id', function () {
    postTask(['due_date' => '2027-05-10', 'recurrence' => ['kind' => 'weekly', 'interval' => 1, 'weekdays' => ['WE', 'MO']], 'recurrence_mode' => 'completion'])->assertStatus(303);
    $task = Task::first();
    expect($task->recurrence_rule)->toBe('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE')->and($task->recurrence_mode)->toBe('completion')->and($task->series_id)->not->toBeNull();
});

it('anchors monthly patterns on the due date day', function () {
    postTask(['due_date' => '2027-01-31', 'recurrence' => ['kind' => 'monthly_day', 'interval' => 1, 'monthDay' => 5], 'recurrence_mode' => 'schedule']);
    expect(lastRule())->toBe('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1');

    foreach ([['monthDay' => 3], [], ['monthDay' => 99]] as $extra) {
        postTask(['due_date' => '2027-01-31', 'recurrence' => ['kind' => 'custom', 'interval' => 3, 'unit' => 'month'] + $extra, 'recurrence_mode' => 'schedule'])->assertStatus(303);
        expect(lastRule())->toBe('FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=28,29,30,31;BYSETPOS=-1');
    }

    postTask(['due_date' => '2027-01-31', 'recurrence' => ['kind' => 'custom', 'interval' => 1, 'unit' => 'month'], 'recurrence_mode' => 'schedule']);
    expect(lastRule())->toBe('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1');
});

it('re-anchors a monthly rule when the due date moves and keeps it when saved untouched', function () {
    postTask(['due_date' => '2027-01-31', 'recurrence' => ['kind' => 'custom', 'interval' => 2, 'unit' => 'month'], 'recurrence_mode' => 'schedule']);
    $task = Task::first();
    $pattern = ['kind' => 'custom', 'interval' => 2, 'unit' => 'month'];

    // untouched save with a different anchor day in the form keeps the stored rule
    Task::whereKey($task->id)->update(['due_date' => '2027-02-28']);
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-02-28', 'recurrence' => $pattern, 'recurrence_mode' => 'schedule'])->assertStatus(303);
    expect($task->fresh()->recurrence_rule)->toContain('BYSETPOS=-1');

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-01-15', 'recurrence' => $pattern, 'recurrence_mode' => 'schedule'])->assertStatus(303);
    expect($task->fresh()->recurrence_rule)->toBe('FREQ=MONTHLY;INTERVAL=2;BYMONTHDAY=15');

    // due date alone moves the anchor
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-01-20'])->assertStatus(303);
    expect($task->fresh()->recurrence_rule)->toBe('FREQ=MONTHLY;INTERVAL=2;BYMONTHDAY=20');
});

it('keeps the series id when the repeat is removed and clears the rule with the due date', function () {
    postTask(['due_date' => '2027-05-10', 'recurrence' => ['kind' => 'daily', 'interval' => 1], 'recurrence_mode' => 'schedule']);
    $task = Task::first();
    $series = $task->series_id;

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-05-10', 'recurrence' => null])->assertStatus(303);
    $fresh = $task->fresh();
    expect($fresh->recurrence_rule)->toBeNull()->and($fresh->recurrence_mode)->toBeNull()->and($fresh->series_id)->toBe($series);

    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-05-10', 'recurrence' => ['kind' => 'daily', 'interval' => 1], 'recurrence_mode' => 'schedule']);
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => null])->assertStatus(303);
    expect($task->fresh()->recurrence_rule)->toBeNull()->and($task->fresh()->recurrence_mode)->toBeNull();
});
