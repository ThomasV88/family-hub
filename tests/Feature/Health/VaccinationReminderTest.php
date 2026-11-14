<?php

use App\Access\AccessLevel;
use App\Jobs\SendOutboxItem;
use App\Medical\Queries\SystemQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Reminders\VaccinationDueHandler;
use App\Medical\Reminders\VaccinationDueNotification;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Push\PushSender;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\VAPID;
use Tests\Support\FakePushSender;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2027-03-01 08:30', 'Europe/Bratislava'));
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey'], 'mail.default' => 'array']);
    $this->fake = new FakePushSender;
    app()->instance(PushSender::class, $this->fake);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: MedicalSubject, 2: MedicalVaccination} */
function reminderSetup(string $due = '2027-03-15', string $name = 'Eva Zdravá', string $locale = 'en'): array
{
    $user = User::factory()->create(['locale' => $locale, 'name' => 'Rodič']);
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['name' => $name], [[$user, AccessLevel::Edit]]);
    $vaccination = MedicalVaccination::factory()->forSubject($person)->due($due)->create(['vaccine' => 'ZxCanaryVaccine', 'notes' => 'ZxCanaryNote']);

    return [$user, $person, $vaccination];
}

function enqueueReminders(): void
{
    Artisan::call('medical:enqueue-reminders', ['--force' => true]);
}

function withPush(User $user): void
{
    app(NotificationPreferences::class)->set($user, 'push', true);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$user->id;
    PushSubscription::query()->create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

function runOutbox(OutboxItem $row): OutboxItem
{
    $row->forceFill(['status' => 'queued'])->save();
    (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));

    return $row->fresh();
}

describe('enqueue', function () {
    it('writes one row per recipient and channel with an id-only payload, once per day', function () {
        [$user, $person, $vaccination] = reminderSetup();
        withPush($user);

        enqueueReminders();
        enqueueReminders();

        $rows = OutboxItem::query()->where('user_id', $user->id)->orderBy('channel')->get();
        expect($rows)->toHaveCount(2)->and($rows->pluck('channel')->all())->toBe(['mail', 'push']);
        $row = $rows->first();
        expect($row->kind)->toBe('vaccination_due')->and($row->subject_type)->toBe('vaccination')->and((int) $row->subject_id)->toBe($vaccination->id)
            ->and($row->payload)->toEqual(['person' => $person->id, 'vaccination' => $vaccination->public_id, 'date' => '2027-03-15', 'lead' => 14])
            ->and($row->dedupe_key)->toBe("vaccination_due:{$vaccination->id}:2027-03-15:14:{$user->id}:mail");
    });

    it('fires lead 0 on the day, one grace day for lead 14 and nothing for the past', function () {
        [$user] = reminderSetup('2027-03-01');
        enqueueReminders();
        expect(OutboxItem::query()->sole()->payload['lead'])->toBe(0);

        OutboxItem::query()->delete();
        MedicalVaccination::query()->delete();
        [$user2] = reminderSetup('2027-03-14');
        enqueueReminders();
        expect(OutboxItem::query()->where('user_id', $user2->id)->sole()->payload['lead'])->toBe(14);

        OutboxItem::query()->delete();
        MedicalVaccination::query()->delete();
        reminderSetup('2027-02-28');
        enqueueReminders();
        expect(OutboxItem::query()->count())->toBe(0);
    });

    it('limits recipients to members who can edit that person', function () {
        [$editor, $person] = reminderSetup();
        $viewer = User::factory()->create();
        MedicalFixtures::grantHealth($viewer);
        $moduleView = User::factory()->create();
        MedicalFixtures::grantHealth($moduleView, AccessLevel::View);
        $admin = User::factory()->create(['is_admin' => true]);
        MedicalFixtures::grantHealth($admin);
        $parent = User::factory()->create();
        MedicalFixtures::grantHealth($parent);
        DB::table('medical_subject_members')->insert([
            ['subject_id' => $person->id, 'user_id' => $viewer->id, 'level' => 1, 'created_at' => now()],
            ['subject_id' => $person->id, 'user_id' => $moduleView->id, 'level' => 2, 'created_at' => now()],
        ]);
        $self = User::factory()->create();
        MedicalFixtures::grantHealth($self);
        $selfPerson = MedicalSubject::factory()->create(['user_id' => $self->id, 'self_access_enabled' => true]);
        MedicalVaccination::factory()->forSubject($selfPerson)->due('2027-03-15')->create();
        $off = User::factory()->create();
        MedicalFixtures::grantHealth($off);
        $offPerson = MedicalSubject::factory()->create(['user_id' => $off->id, 'self_access_enabled' => false]);
        MedicalVaccination::factory()->forSubject($offPerson)->due('2027-03-15')->create();

        enqueueReminders();

        $ids = OutboxItem::query()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        expect($ids)->toBe(collect([$editor->id, $self->id])->sort()->values()->all());
    });

    it('respects the mail preference and requires a push subscription for push', function () {
        [$user] = reminderSetup();
        app(NotificationPreferences::class)->set($user, 'mail', false);
        app(NotificationPreferences::class)->set($user, 'push', true);

        enqueueReminders();
        expect(OutboxItem::query()->count())->toBe(0);

        withPush($user);
        enqueueReminders();
        expect(OutboxItem::query()->sole()->channel)->toBe('push');
    });

    it('runs only at the family reminder hour unless forced, and is scheduled hourly', function () {
        reminderSetup();
        Carbon::setTestNow(Carbon::parse('2027-03-01 09:10', 'Europe/Bratislava'));
        $this->artisan('medical:enqueue-reminders')->assertSuccessful();
        expect(OutboxItem::query()->count())->toBe(0);

        Carbon::setTestNow(Carbon::parse('2027-03-01 08:05', 'Europe/Bratislava'));
        $this->artisan('medical:enqueue-reminders')->assertSuccessful();
        expect(OutboxItem::query()->count())->toBe(1);

        $this->artisan('schedule:list')->expectsOutputToContain('medical:enqueue-reminders')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'medical:enqueue-reminders'));
        expect($event->expression)->toBe('0 * * * *')->and($event->withoutOverlapping)->toBeTrue();
    });

    it('uses the family date around midnight in the family timezone', function () {
        reminderSetup('2027-03-15');
        // 2027-03-14 23:30 UTC is already 2027-03-15 00:30 in Bratislava; reminder hour set to 0 for the check.
        config(['medical.reminder_hour' => 0]);
        Carbon::setTestNow(Carbon::parse('2027-03-14 23:30', 'UTC'));
        $this->artisan('medical:enqueue-reminders')->assertSuccessful();
        // daysAway 0 in family time: lead 0 only.
        expect(OutboxItem::query()->sole()->payload['lead'])->toBe(0);
    });

    it('keeps vaccine, notes and person name out of every outbox column', function () {
        [$user] = reminderSetup();
        withPush($user);
        enqueueReminders();

        $dump = json_encode(DB::table('notification_outbox')->get());
        foreach (['ZxCanaryVaccine', 'ZxCanaryNote', 'Eva', 'Zdrav'] as $needle) {
            expect($dump)->not->toContain($needle);
        }
    });
});

describe('send time', function () {
    it('sends a localized mail naming the person with neither vaccine nor date', function (string $locale, string $subject) {
        [$user] = reminderSetup('2027-03-15', "Mary-Ann O'Brien", $locale);
        enqueueReminders();
        $row = runOutbox(OutboxItem::query()->sole());

        expect($row->status->value)->toBe('sent');
        $messages = app('mailer')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1);
        $email = $messages[0]->getOriginalMessage();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();
        expect($email->getSubject())->toBe($subject)
            ->and($text)->toContain("Mary-Ann O'Brien")->not->toContain('\\')->toContain(url('/health/people/'.MedicalSubject::query()->sole()->id))
            ->and($html)->toContain('Mary');
        foreach ([$text, $html, $email->getSubject()] as $part) {
            expect($part)->not->toContain('ZxCanary')->not->toContain('2027')->not->toContain('15');
        }
        expect($html)->not->toMatch('/March|marca|marec/u');
    })->with([
        ['en', "Vaccination due: Mary-Ann O'Brien"],
        ['sk', "Termín očkovania: Mary-Ann O'Brien"],
        ['pl', "Termin szczepienia: Mary-Ann O'Brien"],
    ]);

    it('pushes the title only with a relative person url', function () {
        [$user, $person] = reminderSetup();
        app(NotificationPreferences::class)->set($user, 'mail', false);
        withPush($user);
        enqueueReminders();

        runOutbox(OutboxItem::query()->sole());

        expect($this->fake->sent)->toHaveCount(1);
        $message = $this->fake->sent[0]['message'];
        expect($message['title'])->toBe('Vaccination due: Eva Zdravá')->and($message['url'])->toBe('/health/people/'.$person->id)
            ->and($message)->not->toHaveKey('body');
        expect(json_encode($message))->not->toContain('ZxCanary');
    });

    it('skips without sending or retrying for every revocation path', function (Closure $revoke) {
        [$user, $person, $vaccination] = reminderSetup();
        enqueueReminders();
        $row = OutboxItem::query()->sole();
        $revoke($user, $person, $vaccination);

        $done = runOutbox($row);

        expect($done->status->value)->toBe('skipped')->and($done->attempts)->toBe(1)->and($done->last_error)->toBeNull();
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
    })->with([
        'membership removed' => [fn ($u, $p) => DB::table('medical_subject_members')->where('subject_id', $p->id)->where('user_id', $u->id)->delete()],
        'member level lowered' => [fn ($u, $p) => DB::table('medical_subject_members')->where('subject_id', $p->id)->update(['level' => 1])],
        'module lowered to view' => [fn ($u) => MedicalFixtures::grantHealth($u, AccessLevel::View)],
        'module removed' => [fn ($u) => $u->modulePermissions()->delete()],
        'vaccination deleted' => [fn ($u, $p, $v) => $v->delete()],
        'due date changed' => [fn ($u, $p, $v) => DB::table('medical_vaccinations')->where('id', $v->id)->update(['next_due_on' => '2027-06-01'])],
        'due date cleared' => [fn ($u, $p, $v) => DB::table('medical_vaccinations')->where('id', $v->id)->update(['next_due_on' => null])],
        'subject deleted' => [fn ($u, $p) => $p->delete()],
        'lead removed from config' => [fn () => config(['medical.vaccination_lead_days' => [7]])],
        'due date already past' => [fn () => Carbon::setTestNow(Carbon::parse('2027-03-20 09:00', 'Europe/Bratislava'))],
    ]);

    it('skips when the linked self access is switched off', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);
        $person = MedicalSubject::factory()->create(['user_id' => $user->id, 'self_access_enabled' => true]);
        MedicalVaccination::factory()->forSubject($person)->due('2027-03-15')->create();
        enqueueReminders();
        $person->forceFill(['self_access_enabled' => false])->save();

        expect(runOutbox(OutboxItem::query()->sole())->status->value)->toBe('skipped');
    });

    it('goes irrelevant after the dose is marked as given', function () {
        [$user, $person, $vaccination] = reminderSetup();
        enqueueReminders();
        $row = OutboxItem::query()->sole();

        $ok = app(VaccinationQuery::class)->markGiven(Viewer::for($user), $person, $vaccination->public_id, '2027-03-02', '2037-03-02');
        expect($ok)->toBeTrue();

        expect(runOutbox($row)->status->value)->toBe('skipped');
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('answers with a boolean and never throws for a refusal or a malformed row', function () {
        [$user, $person, $vaccination] = reminderSetup();
        enqueueReminders();
        $row = OutboxItem::query()->sole();
        $handler = app(VaccinationDueHandler::class);

        expect($handler->stillRelevant($row, $user))->toBeTrue();
        $stranger = User::factory()->create();
        MedicalFixtures::grantHealth($stranger);
        expect($handler->stillRelevant($row, $stranger))->toBeFalse();
        $row->payload = ['person' => 'not-a-uuid', 'date' => 'x', 'lead' => 'y'];
        expect($handler->stillRelevant($row, $user))->toBeFalse();
        $row->payload = [];
        expect($handler->stillRelevant($row, $user))->toBeFalse();
    });

    it('never leaks vaccine or notes into outbox rows, last_error or logs', function () {
        Log::spy();
        [$user] = reminderSetup();
        withPush($user);
        enqueueReminders();
        foreach (OutboxItem::query()->get() as $row) {
            runOutbox($row);
        }

        $dump = json_encode(DB::table('notification_outbox')->get());
        expect($dump)->not->toContain('ZxCanary');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    });
});

describe('system query', function () {
    it('returns candidates, editor flag, due rows and name for the reminder', function () {
        [$user, $person, $vaccination] = reminderSetup();
        $other = User::factory()->create();
        $q = app(SystemQuery::class);

        expect($q->reminderCandidateIds($person->id))->toBe([$user->id])
            ->and($q->isEditor($person->id, $user->id))->toBeTrue()->and($q->isEditor($person->id, $other->id))->toBeFalse()
            ->and($q->reminderCandidateIds('nope'))->toBe([])
            ->and($q->dueVaccinations('2027-03-01', '2027-03-14'))->toBe([])
            ->and($q->dueVaccinations('2027-03-01', '2027-03-15'))->toBe([['id' => $vaccination->id, 'public_id' => $vaccination->public_id, 'subject_id' => $person->id, 'next_due_on' => '2027-03-15']])
            ->and($q->vaccinationDue($vaccination->id))->toBe(['public_id' => $vaccination->public_id, 'subject_id' => $person->id, 'next_due_on' => '2027-03-15'])
            ->and($q->vaccinationDue(0))->toBeNull()
            ->and($q->subjectDisplayName($person->id))->toBe('Eva Zdravá')->and($q->subjectDisplayName('nope'))->toBeNull();
    });
});

it('has a notification class that names no vaccine', function () {
    expect(VaccinationDueNotification::title('Eva', 'en'))->toBe('Vaccination due: Eva');
});
