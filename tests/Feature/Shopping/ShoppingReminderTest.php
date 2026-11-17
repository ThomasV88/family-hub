<?php

use App\Access\AccessLevel;
use App\Jobs\SendOutboxItem;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Notifications\Outbox\ShoppingReminderHandler;
use App\Push\PushSender;
use App\Shopping\ListService;
use App\Shopping\ListStatus;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Minishlink\WebPush\VAPID;
use Tests\Support\FakePushSender;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2027-05-10 10:00:00', 'UTC'));
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
    $this->fake = new FakePushSender;
    app()->instance(PushSender::class, $this->fake);
});

afterEach(fn () => Carbon::setTestNow());

function pushOn(User $user): User
{
    app(NotificationPreferences::class)->set($user, 'push', true);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/u'.$user->id;
    PushSubscription::query()->create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ]);

    return $user;
}

function dueList(User $creator, ?User $responsible = null, array $over = []): ShoppingList
{
    $list = F::list($creator, $responsible);
    $list->forceFill(array_merge(['remind_at' => now()->subMinutes(5)], $over))->save();

    return $list->fresh();
}

function run(): void
{
    test()->artisan('shopping:enqueue-reminders')->assertSuccessful();
}

it('queues one push row for the responsible person only, once', function () {
    $creator = pushOn(F::user());
    $resp = pushOn(F::user());
    $contrib = pushOn(F::user());
    $viewer = pushOn(F::user());
    $list = F::list($creator, $resp, contributors: [$contrib], viewers: [$viewer]);
    $list->forceFill(['remind_at' => now()->subMinutes(5)])->save();

    run();
    run();

    $row = OutboxItem::query()->sole();
    expect($row->kind)->toBe('shopping_reminder')
        ->and($row->channel)->toBe('push')
        ->and($row->user_id)->toBe($resp->id)
        ->and($row->subject_id)->toBe($list->id)
        ->and($row->dedupe_key)->toBe("shopping_reminder:{$list->id}:{$list->fresh()->remind_at->timestamp}:{$resp->id}:push")
        ->and($row->payload)->toBe(['list_id' => $list->id, 'remind_at' => $list->fresh()->remind_at->timestamp])
        ->and($list->fresh()->reminder_enqueued_at)->not->toBeNull();
});

it('consumes the reminder without a row when push is off or there is no subscription', function () {
    $off = F::user();
    app(NotificationPreferences::class)->set($off, 'push', true); // on, but no subscription
    $a = dueList($off);
    $b = F::user();
    pushOn($b);
    app(NotificationPreferences::class)->set($b, 'push', false);
    $c = dueList($b);

    run();

    expect(OutboxItem::query()->count())->toBe(0)
        ->and($a->fresh()->reminder_enqueued_at)->not->toBeNull()
        ->and($c->fresh()->reminder_enqueued_at)->not->toBeNull();
});

it('gives the creator the reminder when the responsible person was removed, lost access or cannot sign in', function (string $how) {
    $creator = pushOn(F::user());
    $resp = pushOn(F::user());
    $list = dueList($creator, $resp);
    match ($how) {
        'deleted' => $resp->delete(),
        'revoked' => F::setLevel($resp, AccessLevel::None),
        'disabled' => $resp->forceFill(['sign_in_disabled' => true])->save(),
    };

    run();

    expect(OutboxItem::query()->sole()->user_id)->toBe($creator->id);
})->with(['deleted', 'revoked', 'disabled']);

it('queues nothing for a done list or a future reminder, and again after the time is changed', function () {
    $creator = pushOn(F::user());
    $done = dueList($creator);
    $done->forceFill(['status' => ListStatus::Done, 'done_at' => now()])->save();
    $future = dueList($creator, null, ['remind_at' => now()->addHour()]);

    run();
    expect(OutboxItem::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addHours(2));
    run();
    expect(OutboxItem::query()->sole()->subject_id)->toBe($future->id);

    app(ListService::class)->updateSettings($creator, $future->id, ['remind_at' => now()->addMinutes(30)->toImmutable()]);
    expect($future->fresh()->reminder_enqueued_at)->toBeNull();
    Carbon::setTestNow(now()->addHour());
    run();
    expect(OutboxItem::query()->where('subject_id', $future->id)->count())->toBe(2);
});

it('is scheduled every minute without overlapping', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

    expect($events['shopping-enqueue-reminders']->expression)->toBe('* * * * *')
        ->and($events['shopping-enqueue-reminders']->withoutOverlapping)->toBeTrue();
});

function reminderRow(): OutboxItem
{
    run();

    return OutboxItem::query()->latest('id')->firstOrFail();
}

function deliver(OutboxItem $row): void
{
    $row->forceFill(['status' => 'queued'])->save();
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));
}

it('delivers a localised push that links to the list', function () {
    $resp = pushOn(F::user());
    $resp->forceFill(['locale' => 'sk'])->save();
    $store = ShoppingStore::query()->create(['name' => 'Tesco']);
    $list = dueList(F::user(), $resp, ['name' => 'Víkend', 'store_id' => $store->id]);
    $row = reminderRow();

    deliver($row);

    expect($row->fresh()->status->value)->toBe('sent')
        ->and($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['message']['title'])->toBe('Čas na nákup')
        ->and($this->fake->sent[0]['message']['url'])->toBe('/shopping/lists/'.$list->id);

    $message = app(ShoppingReminderHandler::class)->pushMessage($row, $resp->fresh());
    expect($message['body'])->toBe('Víkend v obchode Tesco.');
    $list->forceFill(['store_id' => null])->save();
    expect(app(ShoppingReminderHandler::class)->pushMessage($row, $resp->fresh())['body'])->toBe('Víkend.');
});

it('drops a stale row at send time', function (string $change) {
    $creator = pushOn(F::user());
    $resp = pushOn(F::user());
    $other = F::user();
    $list = dueList($creator, $resp);
    $row = reminderRow();
    match ($change) {
        'done' => $list->forceFill(['status' => ListStatus::Done, 'done_at' => now()])->save(),
        'time' => $list->forceFill(['remind_at' => now()->addDay()])->save(),
        'responsible' => $list->forceFill(['responsible_id' => $other->id])->save(),
        'access' => F::setLevel($resp, AccessLevel::None),
        'signin' => $resp->forceFill(['sign_in_disabled' => true])->save(),
    };

    deliver($row);

    expect($row->fresh()->status->value)->toBe('skipped')
        ->and($this->fake->sent)->toHaveCount(0);
})->with(['done', 'time', 'responsible', 'access', 'signin']);

it('skips when push was turned off before sending', function () {
    $resp = pushOn(F::user());
    dueList(F::user(), $resp);
    $row = reminderRow();
    app(NotificationPreferences::class)->set($resp, 'push', false);

    deliver($row);

    expect($this->fake->sent)->toHaveCount(0);
});

it('registers the handler and never sends mail', function () {
    $handler = app(OutboxHandlers::class)->for('shopping_reminder');

    expect($handler)->toBeInstanceOf(ShoppingReminderHandler::class);
    $handler->sendMail(new OutboxItem, F::user());
})->throws(LogicException::class);
