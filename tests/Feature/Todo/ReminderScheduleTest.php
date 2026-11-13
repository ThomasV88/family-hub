<?php

use App\Models\Task;
use App\Tasks\TaskStatus;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2027-05-01 10:00:00 UTC');
});

afterEach(function () {
    Carbon::setTestNow();
});

function taskRemindUtc(Task $task): ?string
{
    return $task->fresh()->remind_at?->utc()->format('Y-m-d H:i');
}

it('computes remind_at on create', function () {
    expect(taskRemindUtc(Task::factory()->dueOn('2027-05-10')->create()))->toBe('2027-05-10 06:00');
    expect(taskRemindUtc(Task::factory()->dueOn('2027-05-10', '18:00')->create(['remind_before_minutes' => 60])))->toBe('2027-05-10 15:00');
    expect(taskRemindUtc(Task::factory()->dueOn('2027-05-10')->create(['remind_before_minutes' => null])))->toBeNull();
    expect(taskRemindUtc(Task::factory()->create()))->toBeNull();
});

it('recomputes only when a watched field changes', function () {
    $task = Task::factory()->dueOn('2027-05-10', '18:00')->create(['remind_before_minutes' => 0]);
    expect(taskRemindUtc($task))->toBe('2027-05-10 16:00');

    $task->update(['due_time' => '19:30']);
    expect(taskRemindUtc($task))->toBe('2027-05-10 17:30');

    Task::whereKey($task->id)->update(['remind_at' => null]);
    $task->refresh()->update(['title' => 'Renamed']);
    expect(taskRemindUtc($task))->toBeNull();
});

it('clears on done or skipped and recomputes on revert', function () {
    $task = Task::factory()->dueOn('2027-05-10')->create();

    $task->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();
    expect(taskRemindUtc($task))->toBeNull();

    $task->forceFill(['status' => TaskStatus::Todo, 'completed_at' => null])->save();
    expect(taskRemindUtc($task))->toBe('2027-05-10 06:00');

    $task->forceFill(['skipped_at' => now()])->save();
    expect(taskRemindUtc($task))->toBeNull();
});

it('gives null for a past due date', function () {
    expect(taskRemindUtc(Task::factory()->dueOn('2027-04-20')->create()))->toBeNull();
});

it('handles DST dates', function () {
    Carbon::setTestNow('2027-03-01 10:00:00 UTC');
    expect(taskRemindUtc(Task::factory()->dueOn('2027-03-28')->create()))->toBe('2027-03-28 06:00');
    expect(taskRemindUtc(Task::factory()->dueOn('2027-10-31')->create()))->toBe('2027-10-31 07:00');
});
