<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    Carbon::setTestNow('2027-05-01 10:00:00 UTC');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
});

afterEach(fn () => Carbon::setTestNow());

function postReminder(array $data)
{
    return test()->actingAs(test()->parent)->post('/todo/tasks', ['title' => 'Remind'] + $data);
}

it('needs a time for minute and hour offsets', function () {
    foreach ([15, 60] as $minutes) {
        postReminder(['due_date' => '2027-05-10', 'remind_before_minutes' => $minutes])
            ->assertSessionHasErrors(['remind_before_minutes' => 'todo.reminder.need_time']);
    }
    expect(Task::count())->toBe(0);
});

it('needs a due date for any reminder offset', function () {
    postReminder(['remind_before_minutes' => 60])->assertSessionHasErrors(['remind_before_minutes' => 'todo.reminder.need_date']);
    postReminder(['remind_before_minutes' => 0])->assertSessionHasErrors(['remind_before_minutes' => 'todo.reminder.need_date']);
});

it('rejects offsets outside the allowlist', function () {
    postReminder(['due_date' => '2027-05-10', 'due_time' => '18:00', 'remind_before_minutes' => 45])->assertSessionHasErrors('remind_before_minutes');
});

it('stores no reminder for null', function () {
    postReminder(['due_date' => '2027-05-10', 'due_time' => '18:00', 'remind_before_minutes' => null])->assertStatus(303);
    $task = Task::first();
    expect($task->remind_before_minutes)->toBeNull()->and($task->remind_at)->toBeNull();
});

it('schedules a day-before reminder at the same local time', function () {
    postReminder(['due_date' => '2027-05-10', 'due_time' => '18:00', 'remind_before_minutes' => 1440])->assertStatus(303);
    expect(Task::first()->remind_at->utc()->format('Y-m-d H:i'))->toBe('2027-05-09 16:00');
});

it('changes the reminder on update', function () {
    $task = Task::factory()->dueOn('2027-05-10', '18:00')->create(['creator_id' => $this->parent->id]);
    $this->actingAs($this->parent)->patch("/todo/tasks/{$task->id}", ['title' => 'x', 'due_date' => '2027-05-10', 'due_time' => '18:00', 'remind_before_minutes' => 60])->assertStatus(303);
    expect($task->fresh()->remind_at->utc()->format('Y-m-d H:i'))->toBe('2027-05-10 15:00');
});
