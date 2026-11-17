<?php

use App\Access\AccessLevel;
use App\Audit\AuditEvent;
use App\Mail\ShoppingListMail;
use App\Models\AuditEntry;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use App\Shopping\Mail\ShoppingListMailer;
use App\Shopping\Mail\ShoppingListText;
use App\Shopping\MemberRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Mail::fake();
});

function mailUnit(string $key): ShoppingUnit
{
    return ShoppingUnit::query()->where('key', $key)->firstOrFail();
}

function mailFixtureList(?User $creator = null, ListStatus $status = ListStatus::Open)
{
    $creator ??= F::user();
    $creator->forceFill(['name' => 'Anna (demo)'])->save();
    $store = ShoppingStore::factory()->create(['name' => 'Billa']);
    $list = F::list($creator, status: $status);
    $list->forceFill(['name' => 'Weekend', 'store_id' => $store->id])->save();

    return $list->refresh();
}

function postListMail($test, User $user, int $listId, array $addresses)
{
    return $test->actingAs($user)->postJson("/shopping/lists/$listId/mail", ['addresses' => $addresses]);
}

it('builds an unescaped plain body with store, responsible, quantities, units and notes', function () {
    $list = mailFixtureList();
    F::item($list, ['name' => 'Zemiaky', 'quantity' => '2', 'unit_id' => mailUnit('kg')->id]);
    F::item($list, ['name' => "Ben & Jerry's <3", 'quantity' => '1', 'unit_id' => mailUnit('pcs')->id, 'note' => 'vanilla']);
    F::item($list, ['name' => 'Soľ', 'quantity' => '2.50']);

    $text = ShoppingListText::for($list->refresh(), 'en');

    expect($text['subject'])->toBe('Weekend — shopping list')
        ->and($text['body'])->toContain('Store: Billa', 'Responsible: Anna (demo)', '- 2 kg Zemiaky', "Ben & Jerry's <3 (vanilla)", '- 2.5 Soľ')
        ->and($text['body'])->not->toContain('&amp;', '&lt;', 'bought', 'unavailable');
});

it('omits the store line when there is none and the marker on an open list', function () {
    $list = mailFixtureList();
    $list->forceFill(['store_id' => null])->save();
    F::item($list, ['name' => 'Milk', 'state' => ItemState::Bought]);

    $body = ShoppingListText::for($list->refresh(), 'en')['body'];

    expect($body)->not->toContain('Store:')->and($body)->not->toContain('(bought)');
});

it('marks bought and unavailable items on a locked or finished list only', function (ListStatus $status) {
    $list = mailFixtureList(status: $status);
    F::item($list, ['name' => 'Milk', 'state' => ItemState::Bought]);
    F::item($list, ['name' => 'Eggs', 'state' => ItemState::Unavailable]);
    F::item($list, ['name' => 'Rice']);

    $body = ShoppingListText::for($list->refresh(), 'en')['body'];

    expect($body)->toContain('- 1 Milk (bought)', '- 1 Eggs (unavailable)')->and($body)->toContain("- 1 Rice\n");
})->with([ListStatus::Locked, ListStatus::Done]);

it('writes the subject, labels and unit plurals in the sender language', function () {
    $list = mailFixtureList();
    F::item($list, ['name' => 'Mlieko', 'quantity' => '5', 'unit_id' => mailUnit('pack')->id]);
    F::item($list, ['name' => 'Chlieb', 'quantity' => '3', 'unit_id' => mailUnit('pack')->id]);

    $sk = ShoppingListText::for($list->refresh(), 'sk');
    $pl = ShoppingListText::for($list->refresh(), 'pl');

    expect($sk['subject'])->toBe('Weekend — nákupný zoznam')
        ->and($sk['body'])->toContain('Obchod: Billa', 'Zodpovedná osoba: Anna (demo)', '- 5 balení Mlieko', '- 3 balenia Chlieb')
        ->and($pl['subject'])->toBe('Weekend — lista zakupów')
        ->and($pl['body'])->toContain('Sklep: Billa', '- 5 opakowań Mlieko', '- 3 opakowania Chlieb');
});

it('strips CR and LF from the subject', function () {
    $list = mailFixtureList();
    $list->forceFill(['name' => "A\r\nBcc: x"])->save();

    $subject = ShoppingListText::for($list->refresh(), 'en')['subject'];

    expect($subject)->not->toMatch('/[\r\n]/')->and($subject)->toContain('A Bcc: x');
});

it('uses an administrator unit name in the sender language', function () {
    $list = mailFixtureList();
    $custom = ShoppingUnit::factory()->create(['name_en' => 'crate', 'name_sk' => 'debna', 'name_pl' => 'skrzynka']);
    F::item($list, ['name' => 'Beer', 'quantity' => '2', 'unit_id' => $custom->id]);

    expect(ShoppingListText::for($list->refresh(), 'sk')['body'])->toContain('- 2 debna Beer');
});

it('renders the mailable as text only and queues it', function () {
    $mail = new ShoppingListMail('Subj', "Ben & Jerry's <3\n");

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->hasTo('x@y.test'))->toBeFalse();
    // Send it through the real (array) mailer: one text/plain part, no HTML part, text unescaped.
    $sent = (new MailManager(app()))->mailer('array')->to('x@y.test')->sendNow($mail);
    $message = $sent->getSymfonySentMessage()->getOriginalMessage();
    expect($message->getHtmlBody())->toBeNull()
        ->and($message->getTextBody())->toBe("Ben & Jerry's <3\n")
        ->and($message->getReplyTo())->toBe([]);
    expect($mail->envelope()->subject)->toBe('Subj')
        ->and($mail->envelope()->replyTo)->toBe([]);
});

it('queues one mail per address, audits only the count and never the addresses', function () {
    $creator = F::user();
    $contributor = F::user();
    $list = mailFixtureList($creator);
    F::member($list, $contributor, MemberRole::Contributor);
    F::item($list, ['name' => 'Milk']);
    $addresses = ['one@example.test', 'two@example.test', 'three@example.test'];

    postListMail($this, $contributor, $list->id, $addresses)->assertRedirect()->assertStatus(303);

    Mail::assertQueued(ShoppingListMail::class, 3);
    foreach ($addresses as $a) {
        Mail::assertQueued(ShoppingListMail::class, fn ($m) => $m->hasTo($a) && count($m->to) === 1 && $m->cc === [] && $m->bcc === [] && $m->replyTo === []);
    }
    $row = AuditEntry::query()->where('event', AuditEvent::ShoppingListMailed->value)->sole();
    expect($row->actor_id)->toBe($contributor->id)->and($row->target_user_id)->toBeNull()->and($row->meta)->toBe(['recipient_count' => 3]);
    $dump = json_encode($row->toArray());
    foreach ($addresses as $a) {
        expect($dump)->not->toContain($a);
    }
    Mail::assertQueued(ShoppingListMail::class, fn ($m) => ! str_contains($m->mailBody, $creator->email) && ! str_contains($m->mailBody, '@'));
});

it('flashes the toast and the count', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, ['a@example.test'])->assertSessionHas('toast.key', 'shopping.mail.toast_sent')
        ->assertSessionHas('toast.count', 1)->assertSessionHas('toast.params.count', 1)
        ->assertSessionHas('flash_data.mail_sent_count', 1);
});

it('rejects bad address lists with translation keys', function (array $addresses, string $key) {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, $addresses)->assertStatus(422)->assertJsonPath('errors.addresses.0', $key)
        ->assertJsonMissingPath('errors.0');
    Mail::assertNothingQueued();
})->with([
    'six' => [['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test', 'e@x.test', 'f@x.test'], 'shopping.mail.err_too_many'],
]);

it('answers 422 err_invalid for malformed addresses and header injection', function (array $addresses) {
    $user = F::user();
    $list = mailFixtureList($user);

    $response = postListMail($this, $user, $list->id, $addresses)->assertStatus(422);
    expect(json_encode($response->json('errors')))->toContain('shopping.mail.err_invalid');
    Mail::assertNothingQueued();
})->with([
    'plain' => [['not-an-email']],
    'crlf' => [["x@y.com\r\nBcc: z@z.z"]],
    'empty' => [[]],
    'too long' => [[str_repeat('a', 250).'@x.test']],
    'folded quoted local part' => [["\"a\r\n b\"@example.com"]],
    'quoted local part' => [['"a b"@example.com']],
    'lone LF' => [["a@example.com\nBcc: z@z.z"]],
    'comment' => [['a@example.com(comment)']],
    'no dot in host' => [['a@localhost']],
    'single label host' => [['a@b']],
    'angle brackets' => [['<a@example.com>']],
    'space' => [['a b@example.com']],
]);

it('refuses a plain good address next to a bad one without queueing anything', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, ['good@example.com', 'a@localhost'])->assertStatus(422);

    Mail::assertNothingQueued();
    expect(RateLimiter::attempts('shopping-mail-to:'.sha1('good@example.com')))->toBe(0);
});

it('counts +tag variants and case against one per-address cap', function (string $variant) {
    $list = mailFixtureList();
    $creator = User::query()->find($list->creator_id);
    $senders = [$creator];
    for ($i = 0; $i < 5; $i++) {
        $senders[] = F::user();
    }
    foreach ($senders as $s) {
        F::member($list, $s, MemberRole::Contributor);
    }

    $variants = ['x@example.test', 'x+1@example.test', 'X+two@Example.test', 'x+3@example.test', 'x+4@example.test'];
    foreach ($variants as $i => $v) {
        postListMail($this, $senders[$i], $list->id, [$v])->assertStatus(303);
    }
    Mail::assertQueued(ShoppingListMail::class, 5);

    postListMail($this, $senders[5], $list->id, [$variant])->assertStatus(429)->assertJsonPath('error', 'shopping.mail.err_limit');
    Mail::assertQueued(ShoppingListMail::class, 5);
})->with(['plain' => ['x@example.test'], 'new tag' => ['x+99@example.test'], 'upper' => ['X@EXAMPLE.test']]);

it('accepts ordinary addresses with a +tag, dots and a subdomain', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, ['first.last+shop@mail.example.co.uk'])->assertStatus(303);

    Mail::assertQueued(ShoppingListMail::class, 1);
});

it('collapses duplicates and is case-insensitive', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, ['A@B.test', ' a@b.test ', 'a@b.test', 'c@d.test'])->assertStatus(303);

    Mail::assertQueued(ShoppingListMail::class, 2);
});

it('lets six duplicates through as one address', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    postListMail($this, $user, $list->id, array_fill(0, 6, 'same@example.test'))->assertStatus(303);

    Mail::assertQueued(ShoppingListMail::class, 1);
});

it('lets the creator, the responsible person and a contributor mail, in every status', function (ListStatus $status) {
    $creator = F::user();
    $responsible = F::user();
    $contributor = F::user();
    $list = F::list($creator, $responsible, $status, [$contributor]);

    foreach ([$creator, $responsible, $contributor] as $actor) {
        postListMail($this, $actor, $list->id, ['ok'.$actor->id.'@example.test'])->assertStatus(303);
    }
    Mail::assertQueued(ShoppingListMail::class, 3);
})->with([ListStatus::Open, ListStatus::Locked, ListStatus::Done]);

it('refuses viewers 403, outsiders and role-less admins 404, view-only editors 403', function () {
    $creator = F::user();
    $viewer = F::user();
    $outsider = F::user();
    $admin = User::factory()->admin()->create();
    F::setLevel($admin, AccessLevel::Edit);
    $viewOnlyMember = F::user(AccessLevel::View);
    $list = F::list($creator, viewers: [$viewer], contributors: [$viewOnlyMember]);

    postListMail($this, $viewer, $list->id, ['a@example.test'])->assertForbidden();
    postListMail($this, $viewOnlyMember, $list->id, ['a@example.test'])->assertForbidden();
    postListMail($this, $outsider, $list->id, ['a@example.test'])->assertNotFound();
    postListMail($this, $admin, $list->id, ['a@example.test'])->assertNotFound();
    postListMail($this, $creator, 999999, ['a@example.test'])->assertNotFound();
    Mail::assertNothingQueued();
    expect(AuditEntry::query()->where('event', AuditEvent::ShoppingListMailed->value)->count())->toBe(0);
});

it('refuses the eleventh send in an hour with a translated 429', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    for ($i = 1; $i <= 10; $i++) {
        postListMail($this, $user, $list->id, ["u$i@example.test"])->assertStatus(303);
    }
    postListMail($this, $user, $list->id, ['u11@example.test'])->assertStatus(429)->assertJsonPath('error', 'shopping.mail.err_limit');

    Mail::assertQueued(ShoppingListMail::class, 10);
});

it('refuses the whole send when one address is over its daily cap', function () {
    $list = mailFixtureList();
    $creator = User::query()->find($list->creator_id);
    $senders = [$creator];
    for ($i = 0; $i < 5; $i++) {
        $senders[] = F::user();
    }
    foreach ($senders as $s) {
        F::member($list, $s, MemberRole::Contributor);
    }

    for ($i = 0; $i < 5; $i++) {
        postListMail($this, $senders[$i], $list->id, ['target@example.test'])->assertStatus(303);
    }
    Mail::assertQueued(ShoppingListMail::class, 5);

    postListMail($this, $senders[5], $list->id, ['fresh@example.test', 'Target@example.test'])->assertStatus(429)
        ->assertJsonPath('error', 'shopping.mail.err_limit');

    Mail::assertQueued(ShoppingListMail::class, 5);
    expect(AuditEntry::query()->where('event', AuditEvent::ShoppingListMailed->value)->count())->toBe(5);
    // the fresh address was not counted by the refused send
    expect(RateLimiter::attempts('shopping-mail-to:'.sha1('fresh@example.test')))->toBe(0);
});

it('refuses an unsendable address inside the mailer before spending any rate-limit slot', function () {
    $user = F::user();
    $list = mailFixtureList($user);

    expect(F::errors(fn () => app(ShoppingListMailer::class)->send($user, $list, ['ok@example.com', "x\r\n@example.com"])))->toHaveKey('addresses');

    Mail::assertNothingQueued();
    expect(RateLimiter::attempts('shopping-mail-to:'.sha1('ok@example.com')))->toBe(0);
});
