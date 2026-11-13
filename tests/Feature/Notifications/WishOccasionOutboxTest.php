<?php

use App\Access\Preset;
use App\Jobs\SendOutboxItem;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishOccasion;
use App\Models\WishReservation;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Notifications\WishOccasionNotification;
use App\Push\PushSender;
use App\Wishlist\Occasions\OccasionCalendar;
use App\Wishlist\Occasions\ReminderLeadDays;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\VAPID;
use Tests\Support\FakePushSender;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2027-03-01 08:30', 'Europe/Bratislava'));
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey'], 'mail.default' => 'array']);
    $this->fake = new FakePushSender;
    app()->instance(PushSender::class, $this->fake);
    $this->owner = User::factory()->withPreset(Preset::Child)->create(['name' => 'Ema', 'birth_date' => '1990-03-15']);
});

afterEach(fn () => Carbon::setTestNow());

function giverWith(string $locale, bool $push = false): User
{
    $user = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Hana '.$locale, 'locale' => $locale]);
    if ($push) {
        app(NotificationPreferences::class)->set($user, 'push', true);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$locale;
        PushSubscription::query()->create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ]);
    }

    return $user;
}

function runRow(OutboxItem $row): void
{
    $row->forceFill(['status' => 'queued'])->save();
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));
}

function rowFor(User $user, string $channel = 'mail'): OutboxItem
{
    test()->artisan('occasions:enqueue')->assertSuccessful();

    return OutboxItem::query()->where('user_id', $user->id)->where('channel', $channel)->sole();
}

it('sends a localized subject per locale and exactly one mail on repeated dispatch', function (string $locale, string $subject) {
    $giver = giverWith($locale);
    $row = rowFor($giver);
    Mail::fake();
    Notification::fake();

    expect($row->kind)->toBe('wish_occasion');
    $notification = new WishOccasionNotification(app(OccasionCalendar::class)->occurrenceOn('user_birthday', $this->owner->id, '2027-03-15'));
    expect($notification->toMail($giver)->subject)->toBe($subject);
})->with([
    ['sk', 'Ema: narodeniny o 14 dní'],
    ['pl', 'Ema: urodziny za 14 dni'],
    ['en', 'Ema: birthday in 14 days'],
]);

it('delivers one mail through the outbox even when run twice', function () {
    $giver = giverWith('en');
    $row = rowFor($giver);

    runRow($row);
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));

    expect($row->fresh()->status->value)->toBe('sent');
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('renders person, occasion and date with a relative list link and nothing about wishes', function (string $locale) {
    $wish = Wish::factory()->create(['owner_id' => $this->owner->id, 'title' => 'SECRET-WISH-TITLE', 'price_minor' => 98765]);
    $giver = giverWith($locale);
    $reserver = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Zdenko Rezervator']);
    WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $reserver->id]);

    $occurrence = app(OccasionCalendar::class)->occurrenceOn('user_birthday', $this->owner->id, '2027-03-15');
    $mail = (new WishOccasionNotification($occurrence))->toMail($giver);
    $html = (string) $mail->render();

    expect($mail->actionUrl)->toBe(url('/wishlist?member='.$this->owner->id))
        ->and($html)->toContain('Ema')->toContain(['sk' => '15. marca 2027', 'pl' => '15 marca 2027', 'en' => 'March 15, 2027'][$locale]);
    foreach (['SECRET-WISH-TITLE', '987', 'Zdenko', 'Rezervator', 'co-buy', 'spoluk', 'wspólny zakup', 'zrzut'] as $needle) {
        expect(mb_strtolower($html))->not->toContain(mb_strtolower($needle));
    }
    // Our own text (the vendor layout footer says "All rights reserved").
    $own = $mail->subject.' '.implode(' ', $mail->introLines).' '.$mail->actionText.' '.$mail->greeting;
    expect(preg_match('/(reserv|rezerv|rezerw)/iu', $own))->toBe(0);
})->with(['sk', 'pl', 'en']);

it('escapes markdown in names and custom titles', function () {
    $giver = giverWith('en');
    $owner = User::factory()->withPreset(Preset::Child)->create(['name' => '[Ema](https://evil.example/n)']);
    $occasion = WishOccasion::factory()->create(['title' => '[Click](https://evil.example/x) **b**', 'month' => 3, 'day' => 15, 'year' => 2027]);
    $occasion->forceFill(['owner_id' => $owner->id])->save();

    $occurrence = app(OccasionCalendar::class)->occurrenceOn('wish_occasion', $occasion->id, '2027-03-15');
    $html = (string) (new WishOccasionNotification($occurrence))->toMail($giver)->render();

    expect($html)->not->toContain('href="https://evil.example')->not->toContain('<strong>b');
});

it('pushes title and a relative url without body', function () {
    $giver = giverWith('sk', push: true);
    $row = rowFor($giver, 'push');

    runRow($row);

    expect($this->fake->sent)->toHaveCount(1);
    $message = $this->fake->sent[0]['message'];
    expect($message['title'])->toBe('Ema: narodeniny o 14 dní')
        ->and($message['url'])->toBe('/wishlist?member='.$this->owner->id)
        ->and($message)->not->toHaveKey('body');
});

it('uses the family title and url for Christmas and says today on the day', function () {
    $giver = giverWith('en', push: true);
    Carbon::setTestNow(Carbon::parse('2027-12-10 09:00', 'Europe/Bratislava'));
    $row = rowFor($giver, 'push');
    runRow($row);
    expect($this->fake->sent[0]['message'])->toMatchArray(['title' => 'Christmas in 14 days', 'url' => '/wishlist']);

    Carbon::setTestNow(Carbon::parse('2027-12-24 09:00', 'Europe/Bratislava'));
    $occurrence = app(OccasionCalendar::class)->occurrenceOn('christmas', 2027, '2027-12-24');
    expect(WishOccasionNotification::title($occurrence, 'en'))->toBe('Christmas today');
});

it('skips the row at send time when it is no longer relevant', function (string $case) {
    $giver = giverWith('en');
    $row = rowFor($giver);
    $owner = $this->owner;

    match ($case) {
        'birth date moved' => $owner->forceFill(['birth_date' => '1990-04-20'])->save(),
        'birth date removed' => $owner->forceFill(['birth_date' => null])->save(),
        'occasion deleted' => (function () use ($row) {
            $o = WishOccasion::factory()->create(['title' => 'X', 'month' => 3, 'day' => 15, 'year' => 2027]);
            $row->forceFill(['subject_type' => 'wish_occasion', 'subject_id' => $o->id])->save();
            $o->delete();
        })(),
        'lost wishlist edit' => $giver->modulePermissions()->where('module', 'wishlist')->update(['level' => 1]),
        'became manager' => WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $giver->id]),
        'lead removed' => app(ReminderLeadDays::class)->set($giver, [7]),
    };

    runRow($row);

    expect($row->fresh()->status->value)->toBe('skipped');
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['birth date moved', 'birth date removed', 'occasion deleted', 'lost wishlist edit', 'became manager', 'lead removed']);

it('sends a real plain-text alternative without Markdown escapes while the HTML part stays escaped', function () {
    $giver = giverWith('en');
    $owner = User::factory()->withPreset(Preset::Child)->create(['name' => "Mary-Ann O'Brien [x](https://evil.example/n)"]);
    $occasion = WishOccasion::factory()->create(['title' => 'St. Nick-day *b*', 'month' => 3, 'day' => 15, 'year' => 2027]);
    $occasion->forceFill(['owner_id' => $owner->id])->save();
    $occurrence = app(OccasionCalendar::class)->occurrenceOn('wish_occasion', $occasion->id, '2027-03-15');

    $giver->notifyNow(new WishOccasionNotification($occurrence));

    $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    $text = $message->getTextBody();
    $html = $message->getHtmlBody();
    expect($text)->toContain("Mary-Ann O'Brien [x](https://evil.example/n)")->toContain('St. Nick-day *b*')
        ->not->toContain('\\')
        ->and($html)->not->toContain('href="https://evil.example')->not->toContain('<strong>b');
});
