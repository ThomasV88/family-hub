<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishOccasion;
use App\Models\WishReservation;
use App\Notifications\NotificationPreferences;
use App\Wishlist\Occasions\ReminderLeadDays;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

/** Local time in Europe/Bratislava. */
function localNow(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Bratislava'));
}

function wishUser(string $name, array $attrs = []): User
{
    return User::factory()->withPreset(Preset::Parent)->create(['name' => $name] + $attrs);
}

function occasionSetup(): array
{
    $owner = wishUser('Ema', ['birth_date' => '1990-03-15']);
    $a = wishUser('Anna');
    $b = wishUser('Boris');
    $viewer = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
    $manager = wishUser('Manager');
    WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $manager->id]);

    return compact('owner', 'a', 'b', 'viewer', 'manager');
}

function outboxFor(User $user): int
{
    return OutboxItem::query()->where('user_id', $user->id)->count();
}

it('writes one mail row per giver and lead, never for the owner, managers or view-only members', function () {
    $s = occasionSetup();
    localNow('2027-03-01 08:30');

    $this->artisan('occasions:enqueue')->assertSuccessful();
    $this->artisan('occasions:enqueue')->assertSuccessful();

    expect(outboxFor($s['a']))->toBe(1)->and(outboxFor($s['b']))->toBe(1)
        ->and(outboxFor($s['owner']))->toBe(0)->and(outboxFor($s['manager']))->toBe(0)->and(outboxFor($s['viewer']))->toBe(0);

    $row = OutboxItem::query()->where('user_id', $s['a']->id)->sole();
    expect($row->kind)->toBe('wish_occasion')->and($row->channel)->toBe('mail')->and($row->subject_type)->toBe('user_birthday')
        ->and($row->payload)->toEqual(['type' => 'user_birthday', 'subject_id' => $s['owner']->id, 'date' => '2027-03-15', 'lead' => 14])
        ->and($row->dedupe_key)->toBe("wish_occasion:birthday:{$s['owner']->id}:2027-03-15:14:{$s['a']->id}:mail");
});

it('waits for 08:00 family time and allows one day of grace', function () {
    $s = occasionSetup();

    localNow('2027-03-01 07:30');
    $this->artisan('occasions:enqueue');
    expect(OutboxItem::query()->count())->toBe(0);

    localNow('2027-03-02 08:30');
    $this->artisan('occasions:enqueue');
    expect(outboxFor($s['a']))->toBe(1);

    OutboxItem::query()->delete();
    localNow('2027-03-03 08:30');
    $this->artisan('occasions:enqueue');
    expect(OutboxItem::query()->count())->toBe(0);
});

it('honours per-giver lead days including none', function () {
    $s = occasionSetup();
    app(ReminderLeadDays::class)->set($s['a'], [7]);
    app(ReminderLeadDays::class)->set($s['b'], []);

    localNow('2027-03-01 09:00');
    $this->artisan('occasions:enqueue');
    expect(outboxFor($s['a']))->toBe(0)->and(outboxFor($s['b']))->toBe(0);

    localNow('2027-03-08 09:00');
    $this->artisan('occasions:enqueue');
    expect(outboxFor($s['a']))->toBe(1)->and(outboxFor($s['b']))->toBe(0);
    expect(OutboxItem::query()->where('user_id', $s['a']->id)->sole()->payload['lead'])->toBe(7);
});

it('adds a push row only with push on and a subscription', function () {
    $s = occasionSetup();
    $prefs = app(NotificationPreferences::class);
    $prefs->set($s['a'], 'push', true);
    $prefs->set($s['b'], 'push', true);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/occ';
    PushSubscription::query()->create([
        'user_id' => $s['a']->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ]);

    localNow('2027-03-01 08:30');
    $this->artisan('occasions:enqueue');

    expect(OutboxItem::query()->where('user_id', $s['a']->id)->pluck('channel')->sort()->values()->all())->toBe(['mail', 'push'])
        ->and(OutboxItem::query()->where('user_id', $s['b']->id)->pluck('channel')->all())->toBe(['mail']);
});

it('sends Christmas and family-wide occasions to every giver and respects owners on custom ones', function () {
    $s = occasionSetup();
    $family = WishOccasion::factory()->create(['title' => 'Family picnic', 'month' => 12, 'day' => 10]);
    $own = WishOccasion::factory()->create(['title' => 'Ema party', 'month' => 12, 'day' => 10]);
    $own->forceFill(['owner_id' => $s['owner']->id])->save();

    localNow('2027-11-26 08:30');
    $this->artisan('occasions:enqueue');
    expect(OutboxItem::query()->where('subject_type', 'christmas')->count())->toBe(0);

    localNow('2027-12-10 08:30');
    $this->artisan('occasions:enqueue');

    $christmas = OutboxItem::query()->where('subject_type', 'christmas')->get();
    expect($christmas->pluck('user_id')->unique()->count())->toBe(User::query()->whereHas('modulePermissions', fn ($q) => $q->where('module', 'wishlist')->where('level', 2))->count() - 0)
        ->and($christmas->first()->subject_id)->toBe(2027)
        ->and($christmas->first()->payload['lead'])->toBe(14);

    expect(OutboxItem::query()->where('subject_id', $family->id)->where('subject_type', 'wish_occasion')->where('payload->lead', 3)->count())->toBe(0);
    expect(OutboxItem::query()->where('subject_id', $own->id)->where('subject_type', 'wish_occasion')->whereIn('user_id', [$s['owner']->id, $s['manager']->id])->count())->toBe(0);
});

it('enqueues name days and one-off occasions but never a past one-off', function () {
    $s = occasionSetup();
    WishOccasion::factory()->nameday('sk', 'Ema', 3, 15)->create(['owner_id' => $s['owner']->id]);
    $past = WishOccasion::factory()->create(['title' => 'Old', 'month' => 3, 'day' => 1, 'year' => 2027]);
    $oneOff = WishOccasion::factory()->create(['title' => 'Wedding', 'month' => 3, 'day' => 15, 'year' => 2027]);

    localNow('2027-03-02 08:30');
    $this->artisan('occasions:enqueue');

    expect(OutboxItem::query()->where('user_id', $s['a']->id)->where('subject_id', $oneOff->id)->count())->toBe(1)
        ->and(OutboxItem::query()->where('subject_id', $past->id)->count())->toBe(0)
        ->and(OutboxItem::query()->where('user_id', $s['a']->id)->where('dedupe_key', 'like', 'wish_occasion:nameday:%')->count())->toBe(1);
});

it('carries ids and dates only and never touches reservations', function () {
    $s = occasionSetup();
    localNow('2027-03-01 08:30');
    $this->artisan('occasions:enqueue');
    $baseline = OutboxItem::query()->orderBy('dedupe_key')->pluck('dedupe_key')->all();

    OutboxItem::query()->delete();
    $wish = Wish::factory()->create(['owner_id' => $s['owner']->id, 'title' => 'SECRET-WISH']);
    WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $s['a']->id]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $this->artisan('occasions:enqueue');

    expect(OutboxItem::query()->orderBy('dedupe_key')->pluck('dedupe_key')->all())->toBe($baseline);
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'wish_reservations') || str_contains($sql, 'wish_contributions'))->count())->toBe(0);
    foreach (OutboxItem::query()->get() as $row) {
        expect(json_encode($row->payload).$row->dedupe_key)->not->toContain('Ema')->not->toContain('SECRET-WISH');
    }
});

it('fires on the right local day across the March DST change', function () {
    $owner = wishUser('Dst', ['birth_date' => '1990-03-28']);
    $giver = wishUser('Giver');

    localNow('2027-03-13 08:30');
    $this->artisan('occasions:enqueue');
    expect(outboxFor($giver))->toBe(0);

    localNow('2027-03-14 08:30');
    $this->artisan('occasions:enqueue');
    expect(OutboxItem::query()->where('user_id', $giver->id)->sole()->payload['date'])->toBe('2027-03-28');
});
