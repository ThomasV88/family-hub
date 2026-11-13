<?php

use App\Access\AccessLevel;
use App\Access\Preset;
use App\Jobs\SendOutboxItem;
use App\Models\ModulePermission;
use App\Models\OutboxItem;
use App\Models\Task;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Notifications\Outbox\OutboxWriter;
use App\Notifications\Outbox\TaskReminderHandler;
use App\Notifications\TaskReminderNotification;
use App\Tasks\ReminderRecipients;
use App\Tasks\TaskStatus;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');
});

afterEach(function () {
    Carbon::setTestNow();
});

function outboxRow(?User $user = null, ?Task $task = null, string $channel = 'mail'): OutboxItem
{
    $user ??= User::factory()->withPreset(Preset::Parent)->create();
    $task ??= Task::factory()->dueOn('2027-05-10')->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
    app(OutboxWriter::class)->enqueue('task_reminder', $user, $channel, 'task', $task->id, "k:{$task->id}:{$user->id}:{$channel}", ['task_id' => $task->id, 'remind_at' => 1]);

    return OutboxItem::query()->latest('id')->first();
}

function runJob(OutboxItem $row): void
{
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));
}

function queued(OutboxItem $row): OutboxItem
{
    $row->forceFill(['status' => 'queued'])->save();

    return $row;
}

it('dispatches due pending rows once and marks them queued', function () {
    Queue::fake();
    $due = outboxRow();
    $later = outboxRow(task: Task::factory()->create());
    $later->forceFill(['available_at' => now()->addHour()])->save();

    $this->artisan('outbox:dispatch')->assertSuccessful();
    $this->artisan('outbox:dispatch')->assertSuccessful();

    Queue::assertPushed(SendOutboxItem::class, 1);
    Queue::assertPushed(SendOutboxItem::class, fn ($j) => $j->outboxId === $due->id);
    expect($due->fresh()->status->value)->toBe('queued')
        ->and($later->fresh()->status->value)->toBe('pending');
});

it('returns rows stuck in queued or sending for over 30 minutes to pending and redispatches', function () {
    Queue::fake();
    $stuck = outboxRow();
    $stuck->forceFill(['status' => 'sending'])->save();
    DB::table('notification_outbox')->where('id', $stuck->id)->update(['updated_at' => now()->subMinutes(31)]);

    $this->artisan('outbox:dispatch');

    Queue::assertPushed(SendOutboxItem::class, fn ($j) => $j->outboxId === $stuck->id);
});

it('does not touch recently claimed rows', function () {
    Queue::fake();
    $fresh = outboxRow();
    $fresh->forceFill(['status' => 'sending'])->save();

    $this->artisan('outbox:dispatch');

    Queue::assertNothingPushed();
    expect($fresh->fresh()->status->value)->toBe('sending');
});

it('sends exactly once even when run twice', function () {
    Notification::fake();
    $row = queued(outboxRow());

    runJob($row);
    runJob($row);

    Notification::assertSentTimes(TaskReminderNotification::class, 1);
    $row->refresh();
    expect($row->status->value)->toBe('sent')->and($row->sent_at)->not->toBeNull()->and($row->attempts)->toBe(1);
});

it('skips when the task is gone, done, or access was revoked', function () {
    Notification::fake();
    $user = User::factory()->withPreset(Preset::Parent)->create();

    $done = outboxRow($user, Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $user->id]));
    Task::query()->whereKey($done->subject_id)->update(['status' => TaskStatus::Done->value, 'completed_at' => now()]);

    $deleted = outboxRow($user, Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $user->id]));
    Task::query()->whereKey($deleted->subject_id)->delete();

    $revoked = outboxRow($user, Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $user->id]));
    ModulePermission::query()->where('user_id', $user->id)->where('module', 'todo')->update(['level' => AccessLevel::None]);

    foreach ([$done, $deleted, $revoked] as $row) {
        runJob(queued($row));
        expect($row->fresh()->status->value)->toBe('skipped');
    }
    Notification::assertNothingSent();
});

it('skips when the task is no longer visible to the user', function () {
    Notification::fake();
    $creator = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $task = Task::factory()->dueOn('2027-05-10')->personal()->create(['creator_id' => $creator->id]);

    runJob(queued(outboxRow($other, $task)));

    Notification::assertNothingSent();
    expect(OutboxItem::query()->sole()->status->value)->toBe('skipped');
});

it('retries with backoff, records only the exception class, and fails after 5 attempts', function () {
    $this->app->bind(TaskReminderHandler::class, fn ($app) => new class($app->make(ReminderRecipients::class)) extends TaskReminderHandler
    {
        public function sendMail(OutboxItem $item, User $user): void
        {
            throw new RuntimeException('Leaky secret title');
        }
    });
    $row = outboxRow();

    foreach ([60, 300, 900, 3600] as $i => $seconds) {
        runJob(queued($row->refresh()));
        $row->refresh();
        expect($row->status->value)->toBe('pending')
            ->and($row->attempts)->toBe($i + 1)
            ->and($row->available_at->equalTo(now()->addSeconds($seconds)))->toBeTrue()
            ->and($row->last_error)->toBe('RuntimeException');
    }

    runJob(queued($row->refresh()));
    $row->refresh();
    expect($row->status->value)->toBe('failed')->and($row->attempts)->toBe(5)
        ->and($row->last_error)->not->toContain('secret');
});

it('skips a push row when the member has push turned off, without sending mail', function () {
    Notification::fake();
    $row = queued(outboxRow(channel: 'push'));

    runJob($row);

    expect($row->fresh()->status->value)->toBe('skipped');
    Notification::assertNothingSent();
});

it('rejects an unknown kind through the registry', function () {
    expect(fn () => app(OutboxHandlers::class)->for('nope'))->toThrow(InvalidArgumentException::class);
});

it('prunes finished rows older than 30 days and keeps the rest', function () {
    $old = outboxRow();
    $old->forceFill(['status' => 'sent'])->save();
    DB::table('notification_outbox')->where('id', $old->id)->update(['updated_at' => now()->subDays(31)]);
    $oldPending = outboxRow(task: Task::factory()->create());
    DB::table('notification_outbox')->where('id', $oldPending->id)->update(['updated_at' => now()->subDays(31)]);
    $recent = outboxRow(task: Task::factory()->create());
    $recent->forceFill(['status' => 'sent'])->save();

    $this->artisan('outbox:prune')->assertSuccessful();

    expect(OutboxItem::query()->pluck('id')->all())->toEqualCanonicalizing([$oldPending->id, $recent->id]);
});

it('schedules outbox:dispatch every minute and outbox:prune daily', function () {
    $events = collect(app(Schedule::class)->events());
    $dispatch = $events->first(fn ($e) => str_contains($e->command, 'outbox:dispatch'));
    $prune = $events->first(fn ($e) => str_contains($e->command, 'outbox:prune'));

    expect($dispatch->expression)->toBe('* * * * *')->and($dispatch->withoutOverlapping)->toBeTrue()
        ->and($prune->expression)->toBe('0 0 * * *');
});

it('does not reset a send that has been running for only 11 minutes', function () {
    Queue::fake();
    $slow = outboxRow();
    $slow->forceFill(['status' => 'sending'])->save();
    DB::table('notification_outbox')->where('id', $slow->id)->update(['updated_at' => now()->subMinutes(11)]);

    $this->artisan('outbox:dispatch');

    Queue::assertNothingPushed();
    expect($slow->fresh()->status->value)->toBe('sending');
});

it('fails a stuck row that exhausted its attempts instead of retrying forever', function () {
    Queue::fake();
    $poison = outboxRow();
    $poison->forceFill(['status' => 'sending', 'attempts' => SendOutboxItem::MAX_ATTEMPTS])->save();
    DB::table('notification_outbox')->where('id', $poison->id)->update(['updated_at' => now()->subMinutes(31)]);

    $this->artisan('outbox:dispatch');

    Queue::assertNothingPushed();
    expect($poison->fresh()->status->value)->toBe('failed')->and($poison->fresh()->last_error)->toBe('stuck');
});

it('does not claim a queued row whose attempts are exhausted', function () {
    Notification::fake();
    $row = queued(outboxRow());
    $row->forceFill(['attempts' => SendOutboxItem::MAX_ATTEMPTS])->save();

    runJob($row);

    Notification::assertNothingSent();
});

it('does not resend when marking a delivered mail as sent fails once', function () {
    Notification::fake();
    $row = queued(outboxRow());
    $GLOBALS['fh_fail_mark'] = 1;
    OutboxItem::saving(function (OutboxItem $m) {
        if (($GLOBALS['fh_fail_mark'] ?? 0) > 0 && $m->status === \App\Notifications\Outbox\OutboxStatus::Sent) {
            $GLOBALS['fh_fail_mark']--;
            throw new RuntimeException('db blip');
        }
    });

    runJob($row);
    $GLOBALS['fh_fail_mark'] = 0;

    Notification::assertSentTimes(TaskReminderNotification::class, 1);
    expect($row->fresh()->status->value)->toBe('sent');
});
