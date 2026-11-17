<?php

use App\Access\Preset;
use App\Auth\InvalidInviteException;
use App\Auth\InviteService;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\ModulePermission;
use App\Models\Task;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Notifications\InviteNotification;
use App\Security\SensitiveSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create([
        'name' => 'Ema', 'email' => null, 'sign_in_disabled' => true, 'password_set' => false,
    ]);
    WishlistManager::query()->create(['owner_id' => $this->child->id, 'manager_id' => $this->parent->id, 'created_by' => $this->admin->id]);
    $this->wish = Wish::factory()->create(['owner_id' => $this->child->id]);
    $this->task = Task::factory()->create(['assignee_id' => $this->child->id]);
});

function convertChild(User $admin, User $child, string $email = 'Ema@Example.test'): Invitation
{
    test()->actingAs($admin)->post("/settings/members/{$child->id}/convert", ['email' => $email, 'locale' => 'sk'])->assertRedirect();

    return Invitation::query()->where('user_id', $child->id)->latest('id')->firstOrFail();
}

function grantsOf(User $u): array
{
    return ModulePermission::query()->where('user_id', $u->id)->orderBy('module')->get()->mapWithKeys(fn ($p) => [$p->module->value => $p->level->value])->all();
}

it('invites the child to an e-mail, queues the mail and keeps the child unable to sign in', function () {
    Notification::fake();
    $invitation = convertChild($this->admin, $this->child);

    expect($invitation->email)->toBe('ema@example.test')->and($invitation->preset)->toBe(Preset::Child);
    Notification::assertSentOnDemand(InviteNotification::class);
    $this->child->refresh();
    expect($this->child->email)->toBeNull()->and($this->child->sign_in_disabled)->toBeTrue()->and($this->child->canSignIn())->toBeFalse();

    $this->actingAs($this->admin)->get('/settings/members')->assertInertia(fn ($page) => $page
        ->where('invitations.0.email', 'ema@example.test'));
});

it('fills the same users row on acceptance and keeps everything the child had', function () {
    $grants = grantsOf($this->child);
    $invitation = convertChild($this->admin, $this->child);
    $token = 'convert-token-123';
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();
    $count = User::count();
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->post("/invite/{$token}", ['name' => 'Ema', 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple'])
        ->assertRedirect('/');

    expect(User::count())->toBe($count);
    $user = User::findOrFail($this->child->id);
    expect($user->email)->toBe('ema@example.test')
        ->and($user->sign_in_disabled)->toBeFalse()
        ->and($user->password_set)->toBeTrue()
        ->and($user->canSignIn())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->locale)->toBe('sk')
        ->and(Hash::check('correct horse battery staple', $user->password))->toBeTrue()
        ->and(grantsOf($user))->toBe($grants)
        ->and(Wish::query()->whereKey($this->wish->id)->value('owner_id'))->toBe($user->id)
        ->and(Task::query()->whereKey($this->task->id)->value('assignee_id'))->toBe($user->id)
        ->and(WishlistManager::query()->where('owner_id', $user->id)->pluck('manager_id')->all())->toBe([$this->parent->id]);
    $this->assertAuthenticatedAs($user);

    expect(Invitation::sole()->accepted_user_id)->toBe($user->id);
    $row = AuditEntry::query()->where('event', 'child_converted')->sole();
    expect($row->target_user_id)->toBe($user->id)->and($row->meta)->toBeNull();
});

it('fills the same row through the Google accept path', function () {
    $invitation = convertChild($this->admin, $this->child);
    $count = User::count();

    $user = app(InviteService::class)->accept($invitation, ['name' => 'Ema'], 'google-sub-ema');

    expect(User::count())->toBe($count)
        ->and($user->id)->toBe($this->child->id)
        ->and($user->google_id)->toBe('google-sub-ema')
        ->and($user->password_set)->toBeFalse()
        ->and($user->sign_in_disabled)->toBeFalse()
        ->and($user->email)->toBe('ema@example.test');
});

it('leaves the child exactly as it was when the invitation is revoked', function () {
    $invitation = convertChild($this->admin, $this->child);
    $before = $this->child->fresh()->only(['email', 'sign_in_disabled', 'password_set', 'password']);

    $this->actingAs($this->admin)->delete("/settings/invitations/{$invitation->id}")->assertRedirect();

    expect($this->child->fresh()->only(['email', 'sign_in_disabled', 'password_set', 'password']))->toBe($before);
    $this->post('/invite/whatever', ['name' => 'X', 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple']);
    expect($this->child->fresh()->canSignIn())->toBeFalse();
    expect(fn () => app(InviteService::class)->accept($invitation->fresh(), ['name' => 'Ema', 'password' => 'correct horse battery staple']))
        ->toThrow(InvalidInviteException::class);
});

it('refuses an e-mail that belongs to a member in any letter case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin)->postJson("/settings/members/{$this->child->id}/convert", ['email' => 'TAKEN@Example.test', 'locale' => 'en'])
        ->assertStatus(422)->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'settings.members.convert.err_email_taken');
    expect(Invitation::count())->toBe(0);
});

it('answers 404 for an adult, 403 for a non-admin and refuses a stale admin', function () {
    $this->actingAs($this->admin)->postJson("/settings/members/{$this->parent->id}/convert", ['email' => 'a@example.test', 'locale' => 'en'])->assertNotFound();
    $this->actingAs($this->admin)->postJson('/settings/members/999999/convert', ['email' => 'a@example.test', 'locale' => 'en'])->assertNotFound();
    $this->actingAs($this->parent)->postJson("/settings/members/{$this->child->id}/convert", ['email' => 'a@example.test', 'locale' => 'en'])->assertForbidden();
    expect(Invitation::count())->toBe(0);

    $this->useRealSensitiveSession();
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin)->postJson("/settings/members/{$this->child->id}/convert", ['email' => 'a@example.test', 'locale' => 'en'])->assertStatus(423);
    expect(Invitation::count())->toBe(0);

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin)->withSession([SensitiveSession::CONFIRMED_KEY => now()->getTimestamp()])
        ->postJson("/settings/members/{$this->child->id}/convert", ['email' => 'a@example.test', 'locale' => 'en'])->assertRedirect();
    expect(Invitation::count())->toBe(1);
});

it('keeps one open invitation per child when the address is changed', function () {
    $first = convertChild($this->admin, $this->child, 'one@example.test');
    $second = convertChild($this->admin, $this->child, 'two@example.test');

    expect($first->fresh()->revoked_at)->not->toBeNull()->and($second->fresh()->revoked_at)->toBeNull();
});

it('refuses the accept when the child row is no longer a child', function () {
    $invitation = convertChild($this->admin, $this->child);
    $this->child->forceFill(['email' => 'else@example.test', 'sign_in_disabled' => false])->save();

    expect(fn () => app(InviteService::class)->accept($invitation, ['name' => 'Ema', 'password' => 'correct horse battery staple']))
        ->toThrow(InvalidInviteException::class);
    expect($this->child->fresh()->email)->toBe('else@example.test');
});

it('turns a unique-index loss of the e-mail race into the already-a-member refusal, not a 500', function () {
    $invitation = convertChild($this->admin, $this->child);

    // Another accept for the same address commits between the uniqueness check and the write.
    User::updating(function (User $user) {
        if ($user->id === $this->child->id) {
            DB::table('users')->insert(['name' => 'Rival', 'email' => 'ema@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    expect(fn () => app(InviteService::class)->accept($invitation, ['name' => 'Ema', 'password' => 'correct horse battery staple']))
        ->toThrow(InvalidInviteException::class);
    User::flushEventListeners();

    $child = $this->child->fresh();
    expect($child->email)->toBeNull()->and($child->sign_in_disabled)->toBeTrue()
        ->and(Invitation::sole()->accepted_at)->toBeNull();
});

it('locks the invitation and the child row in the accept transaction', function () {
    $invitation = convertChild($this->admin, $this->child);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    app(InviteService::class)->accept($invitation, ['name' => 'Ema', 'password' => 'correct horse battery staple']);

    $locks = collect($sql)->filter(fn ($q) => str_contains($q, 'for update'));
    expect($locks->contains(fn ($q) => str_contains($q, '"invitations"')))->toBeTrue()
        ->and($locks->contains(fn ($q) => str_contains($q, '"users"')))->toBeTrue();
});

it('shows the open conversion invitation on the child row only', function () {
    $this->actingAs($this->admin)->get('/settings/members')->assertInertia(fn ($page) => $page
        ->where('members', fn ($members) => collect($members)->every(fn ($m) => $m['invitation'] === null)));

    $invitation = convertChild($this->admin, $this->child);

    $this->actingAs($this->admin)->get('/settings/members')->assertInertia(fn ($page) => $page
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->child->id)['invitation']['email'] === 'ema@example.test'
            && collect($members)->firstWhere('id', $this->parent->id)['invitation'] === null));

    $this->actingAs($this->admin)->delete("/settings/invitations/{$invitation->id}");
    $this->actingAs($this->admin)->get('/settings/members')->assertInertia(fn ($page) => $page
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->child->id)['invitation'] === null));
});

it('removes an open conversion invitation together with the child instead of turning it into a plain invite', function () {
    $invitation = convertChild($this->admin, $this->child);

    // The staging reseed removes a child's own rows first (wishes and tasks restrict the delete), then the child.
    $this->wish->delete();
    $this->task->delete();
    DB::table('wishlist_managers')->where('owner_id', $this->child->id)->delete();
    DB::table('module_permissions')->where('user_id', $this->child->id)->delete();
    DB::table('users')->where('id', $this->child->id)->delete();

    expect(Invitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

it('refuses to invite a child that was converted in the meantime, with the generic failure, and creates nothing', function () {
    $this->child->forceFill(['email' => 'else@example.test', 'sign_in_disabled' => false])->save();

    expect(fn () => app(InviteService::class)->create($this->admin, 'late@example.test', Preset::Child, 'en', $this->child->id))
        ->toThrow(ValidationException::class);
    expect(Invitation::query()->count())->toBe(0);
});

it('takes the per-child lock and the child row lock when it creates a conversion invitation', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    app(InviteService::class)->create($this->admin, 'late@example.test', Preset::Child, 'en', $this->child->id);

    expect(collect($sql)->contains(fn ($q) => str_contains($q, 'pg_advisory_xact_lock')))->toBeTrue()
        ->and(collect($sql)->contains(fn ($q) => str_contains($q, 'from "users"') && str_contains($q, 'for update')))->toBeTrue();
});

it('never shows the accept form again for a link that is valid but cannot be accepted', function () {
    $invitation = convertChild($this->admin, $this->child);
    $token = 'convert-token-456';
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();
    $this->child->forceFill(['email' => 'else@example.test', 'sign_in_disabled' => false])->save();
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->post("/invite/{$token}", ['name' => 'Ema', 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple'])
        ->assertInertia(fn ($page) => $page->where('state', 'revoked')->where('email', null)->where('token', null));
});
