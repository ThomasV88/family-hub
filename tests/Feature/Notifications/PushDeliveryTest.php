<?php

use App\Access\Preset;
use App\Jobs\SendOutboxItem;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Push\PushResult;
use App\Push\PushSender;
use Carbon\Carbon;
use Minishlink\WebPush\VAPID;
use Tests\Support\FakePushSender;

beforeEach(function () {
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
    $this->fake = new FakePushSender;
    app()->instance(PushSender::class, $this->fake);
    $this->user = User::factory()->withPreset(Preset::Parent)->create();
    app(NotificationPreferences::class)->set($this->user, 'push', true);
});

afterEach(fn () => Carbon::setTestNow());

function pushSubscribe(User $user, string $suffix = 'a'): PushSubscription
{
    $endpoint = "https://fcm.googleapis.com/fcm/send/$suffix";

    return PushSubscription::query()->create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

function pushTask(User $user, array $attrs = []): Task
{
    Carbon::setTestNow('2027-05-09 10:00:00 UTC');
    $task = Task::factory()->dueOn('2027-05-10')->create(['assignee_id' => $user->id, 'creator_id' => $user->id] + $attrs);
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');

    return $task;
}

function pushRow(User $user, Task $task): OutboxItem
{
    test()->artisan('reminders:enqueue')->assertSuccessful();
    $row = OutboxItem::query()->where('channel', 'push')->where('subject_id', $task->id)->sole();
    $row->forceFill(['status' => 'queued'])->save();

    return $row;
}

function runPush(OutboxItem $row): void
{
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));
}

it('enqueues a push row only with push on and a subscription', function () {
    $task = pushTask($this->user);
    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->where('channel', 'push')->count())->toBe(0)
        ->and(OutboxItem::query()->where('channel', 'mail')->count())->toBe(1);

    pushSubscribe($this->user);
    $task2 = pushTask($this->user);
    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->where('channel', 'push')->where('subject_id', $task2->id)->count())->toBe(1)
        ->and(OutboxItem::query()->where('channel', 'mail')->where('subject_id', $task2->id)->count())->toBe(1);

    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->count())->toBe(3);

    app(NotificationPreferences::class)->set($this->user, 'push', false);
    $task3 = pushTask($this->user);
    $this->artisan('reminders:enqueue');
    expect(OutboxItem::query()->where('channel', 'push')->where('subject_id', $task3->id)->count())->toBe(0);
});

it('sends title, url and tag only, never notes', function () {
    pushSubscribe($this->user, 'a');
    pushSubscribe($this->user, 'b');
    $task = pushTask($this->user, ['title' => str_repeat('T', 120), 'notes' => 'SECRET NOTES TEXT']);
    $row = pushRow($this->user, $task);

    runPush($row);

    expect($this->fake->sent)->toHaveCount(2);
    $message = $this->fake->sent[0]['message'];
    expect(array_keys($message))->toBe(['title', 'url', 'tag'])
        ->and($message['title'])->toBe('Task due: '.mb_strimwidth(str_repeat('T', 120), 0, 80, '…'))
        ->and($message['url'])->toBe('/todo?task='.$task->id)
        ->and($message['tag'])->toBe(substr(sha1($row->dedupe_key), 0, 16))
        ->and(json_encode($this->fake->sent))->not->toContain('SECRET');
    expect($row->fresh()->status->value)->toBe('sent')
        ->and(PushSubscription::query()->whereNotNull('last_success_at')->count())->toBe(2);
});

it('uses the members locale for the title', function () {
    $this->user->forceFill(['locale' => 'sk'])->save();
    pushSubscribe($this->user);
    $row = pushRow($this->user, pushTask($this->user, ['title' => 'Kosit']));

    runPush($row);

    expect($this->fake->sent[0]['message']['title'])->toBe('Termín úlohy: Kosit');
});

it('deletes expired subscriptions and still sends to the others', function () {
    $dead = pushSubscribe($this->user, 'dead');
    pushSubscribe($this->user, 'live');
    $this->fake->results[$dead->id] = PushResult::Expired;
    $row = pushRow($this->user, pushTask($this->user));

    runPush($row);

    expect(PushSubscription::query()->count())->toBe(1)->and($row->fresh()->status->value)->toBe('sent');
});

it('skips when every subscription expired', function () {
    $dead = pushSubscribe($this->user, 'dead');
    $this->fake->results[$dead->id] = PushResult::Expired;
    $row = pushRow($this->user, pushTask($this->user));

    runPush($row);

    expect(PushSubscription::query()->count())->toBe(0)->and($row->fresh()->status->value)->toBe('skipped');
});

it('retries failures with backoff and fails after five attempts', function () {
    $sub = pushSubscribe($this->user);
    $this->fake->results[$sub->id] = PushResult::Failed;
    $row = pushRow($this->user, pushTask($this->user));

    runPush($row);
    $row->refresh();
    expect($row->status->value)->toBe('pending')->and($row->attempts)->toBe(1)
        ->and($row->available_at->gt(now()))->toBeTrue()->and($row->last_error)->toBe('push_failed');

    foreach (range(2, 5) as $_) {
        $row->forceFill(['status' => 'queued'])->save();
        runPush($row);
        $row->refresh();
    }
    expect($row->status->value)->toBe('failed')->and($row->attempts)->toBe(5);
});

it('skips with push_not_configured when VAPID is missing', function () {
    pushSubscribe($this->user);
    $row = pushRow($this->user, pushTask($this->user));
    config(['push.private_key' => null]);

    runPush($row);

    expect($row->fresh()->status->value)->toBe('skipped')->and($row->fresh()->last_error)->toBe('push_not_configured')
        ->and($this->fake->sent)->toBe([]);
});

it('skips when the subscriptions are gone at send time', function () {
    $sub = pushSubscribe($this->user);
    $row = pushRow($this->user, pushTask($this->user));
    $sub->delete();

    runPush($row);

    expect($row->fresh()->status->value)->toBe('skipped');
});

it('sends once when the job runs twice', function () {
    pushSubscribe($this->user);
    $row = pushRow($this->user, pushTask($this->user));

    runPush($row);
    runPush($row);

    expect($this->fake->sent)->toHaveCount(1);
});
