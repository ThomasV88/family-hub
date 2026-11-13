<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\OutboxItem;
use App\Models\Task;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A task whose reminder (06:00 UTC today) is already due. */
function dueTask(array $attrs = []): Task
{
    Carbon::setTestNow('2027-05-09 10:00:00 UTC');
    $task = Task::factory()->dueOn('2027-05-10')->create($attrs);
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');

    return $task;
}

it('writes one pending mail row for the assignee and clears remind_at', function () {
    $child = User::factory()->withPreset(Preset::Child)->create();
    $task = dueTask(['assignee_id' => $child->id]);
    expect($task->fresh()->remind_at)->not->toBeNull();

    $this->artisan('reminders:enqueue')->assertSuccessful();

    $row = OutboxItem::query()->sole();
    $at = Carbon::parse('2027-05-10 06:00:00 UTC')->timestamp;
    expect($row->user_id)->toBe($child->id)
        ->and($row->channel)->toBe('mail')
        ->and($row->status->value)->toBe('pending')
        ->and($row->kind)->toBe('task_reminder')
        ->and($row->dedupe_key)->toBe("task_reminder:{$task->id}:{$at}:{$child->id}:mail")
        ->and($row->payload)->toBe(['task_id' => $task->id, 'remind_at' => $at])
        ->and($task->fresh()->remind_at)->toBeNull();
});

it('is idempotent across runs and after a simulated crash', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $task = dueTask(['assignee_id' => $user->id]);
    $original = $task->fresh()->remind_at;

    $this->artisan('reminders:enqueue');
    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->count())->toBe(1);

    Task::query()->whereKey($task->id)->update(['remind_at' => $original]);
    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->count())->toBe(1)
        ->and($task->fresh()->remind_at)->toBeNull();
});

it('picks the right recipient', function () {
    $creator = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();

    $unassigned = dueTask(['creator_id' => $creator->id, 'assignee_id' => null]);
    $personal = dueTask(['creator_id' => $creator->id, 'assignee_id' => $other->id, 'visibility' => 'personal']);

    $this->artisan('reminders:enqueue');

    expect(OutboxItem::query()->where('subject_id', $unassigned->id)->sole()->user_id)->toBe($creator->id)
        ->and(OutboxItem::query()->where('subject_id', $personal->id)->sole()->user_id)->toBe($creator->id);
});

it('skips an assignee who cannot see a Selected task', function () {
    $creator = User::factory()->withPreset(Preset::Parent)->create();
    $stranger = User::factory()->withPreset(Preset::Parent)->create();
    dueTask(['creator_id' => $creator->id, 'assignee_id' => $stranger->id, 'visibility' => 'selected']);

    $this->artisan('reminders:enqueue');

    expect(OutboxItem::query()->count())->toBe(0);
});

it('skips recipients without To-do access or with mail turned off', function () {
    $noAccess = User::factory()->withGrant(Module::Todo, AccessLevel::None)->create();
    $quiet = User::factory()->withPreset(Preset::Parent)->create();
    app(NotificationPreferences::class)->set($quiet, 'mail', false);

    dueTask(['assignee_id' => $noAccess->id]);
    dueTask(['assignee_id' => $quiet->id]);

    $this->artisan('reminders:enqueue');

    expect(OutboxItem::query()->count())->toBe(0);
});

it('leaves future, done and skipped tasks untouched', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $future = Task::factory()->dueOn('2027-05-20')->create(['assignee_id' => $user->id]);
    $done = dueTask(['assignee_id' => $user->id]);
    $done->forceFill(['completed_at' => now()])->save();
    $skipped = dueTask(['assignee_id' => $user->id]);
    Task::query()->whereKey($skipped->id)->update(['skipped_at' => now(), 'remind_at' => '2027-05-10 06:00:00+00']);

    $this->artisan('reminders:enqueue');

    expect(OutboxItem::query()->count())->toBe(0)
        ->and($future->fresh()->remind_at)->not->toBeNull();
});

it('limits a run to 200 tasks ordered by remind_at and locks with skip locked', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    foreach (range(1, 201) as $i) {
        dueTask(['assignee_id' => $user->id, 'creator_id' => $user->id]);
    }

    DB::enableQueryLog();
    $this->artisan('reminders:enqueue');
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect(OutboxItem::query()->count())->toBe(200)
        ->and(stripos($sql, 'skip locked'))->not->toBeFalse();
});

it('schedules reminders:enqueue every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'reminders:enqueue'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
