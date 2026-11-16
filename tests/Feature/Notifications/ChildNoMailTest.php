<?php

use App\Access\AccessLevel;
use App\Access\Preset;
use App\Models\MedicalVaccination;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\TaskReminderNotification;
use App\Wishlist\Occasions\OccasionGivers;
use App\Wishlist\Occasions\OccasionOccurrence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

// "It can never authenticate and never receives mail" (phase 10 CONTEXT): no scheduler path may address a child.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2027-03-01 08:30', 'Europe/Bratislava'));
    config(['mail.default' => 'array']);
});

afterEach(fn () => Carbon::setTestNow());

function pushFor(User $user): void
{
    app(NotificationPreferences::class)->set($user, 'push', true);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/child-'.$user->id;
    PushSubscription::query()->create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

it('creates no task reminder row for a child assignee while the creator still gets one', function () {
    $creator = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->child()->withPreset(Preset::Parent)->create();
    pushFor($child);

    Carbon::setTestNow('2027-05-09 10:00:00 UTC');
    $forChild = Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $creator->id, 'assignee_id' => $child->id]);
    $forCreator = Task::factory()->dueOn('2027-05-10')->create(['creator_id' => $creator->id, 'assignee_id' => null]);
    Carbon::setTestNow('2027-05-10 06:30:00 UTC');

    Artisan::call('reminders:enqueue');

    expect(OutboxItem::query()->where('user_id', $child->id)->count())->toBe(0)
        ->and(OutboxItem::query()->where('user_id', $creator->id)->where('subject_id', $forCreator->id)->exists())->toBeTrue()
        ->and(OutboxItem::query()->where('subject_id', $forChild->id)->exists())->toBeFalse();
});

it('excludes a child holding Wishlist Edit from the occasion givers', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create(['birth_date' => '1990-03-15']);
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $child = User::factory()->child()->withPreset(Preset::Parent)->create();

    $occurrence = new OccasionOccurrence(
        OccasionOccurrence::BIRTHDAY, $owner->id, $owner->name, null, null, CarbonImmutable::parse('2027-03-15'), 14,
        OccasionOccurrence::SUBJECT_BIRTHDAY, $owner->id,
    );
    $givers = app(OccasionGivers::class);

    expect(collect($givers->for($occurrence))->pluck('id')->all())->toContain($parent->id)->not->toContain($child->id)
        ->and($givers->isGiver($child, $occurrence))->toBeFalse()
        ->and($givers->isGiver($parent, $occurrence))->toBeTrue();
});

it('writes no occasion outbox row for a child', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create(['birth_date' => '1990-03-15']);
    $child = User::factory()->child()->withPreset(Preset::Parent)->create();
    pushFor($child);

    Artisan::call('occasions:enqueue');

    expect(OutboxItem::query()->where('user_id', $child->id)->count())->toBe(0);
});

it('plans no vaccination reminder for a child linked to a medical subject', function () {
    $parent = User::factory()->create();
    MedicalFixtures::grantHealth($parent);
    $child = User::factory()->child()->create();
    MedicalFixtures::grantHealth($child);
    pushFor($child);
    $person = MedicalFixtures::subject(['name' => 'Eva Zdravá'], [[$parent, AccessLevel::Edit], [$child, AccessLevel::Edit]]);
    MedicalVaccination::factory()->forSubject($person)->due('2027-03-15')->create();

    Artisan::call('medical:enqueue-reminders', ['--force' => true]);

    expect(OutboxItem::query()->where('user_id', $parent->id)->exists())->toBeTrue()
        ->and(OutboxItem::query()->where('user_id', $child->id)->count())->toBe(0);
});

it('has no mail route for a child and the mail channel sends nothing', function () {
    $child = User::factory()->child()->create();
    Mail::fake();

    expect($child->routeNotificationForMail())->toBeNull()
        ->and(User::factory()->create(['email' => 'ok@example.test'])->routeNotificationForMail())->toBe('ok@example.test');

    $task = Task::factory()->create(['creator_id' => $child->id, 'due_date' => '2027-03-10']);
    $child->notify(new TaskReminderNotification($task->fresh()));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
