<?php

use App\Access\Preset;
use App\Help\HelpRegistry;
use App\Help\HelpTexts;
use App\Models\AuditEntry;
use App\Models\HelpText;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function helpAdmin(): User
{
    return User::factory()->admin()->create();
}

it('lists every registered tip with placement, all three texts and the edited markers for an admin', function () {
    $registry = app(HelpRegistry::class);

    $this->actingAs(helpAdmin())->get('/settings/help')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Help', false)
        ->has('sections', 9)
        ->has('topics', count($registry->keys()))
        ->where('topics.0.key', $registry->keys()[0])
        ->has('topics.0.texts.sk')->has('topics.0.texts.pl')->has('topics.0.texts.en')
        ->has('topics.0.defaults')->has('topics.0.edited')->has('topics.0.location.area')->has('topics.0.location.screen')->has('topics.0.location.field')
        ->where('orphans', [])
        ->where('error', false)
        ->where('locales', ['sk', 'pl', 'en'])
        ->where('maxLength', 400));
});

it('shows the contextual help section to admins only', function () {
    $admin = helpAdmin();
    $member = User::factory()->withPreset(Preset::Parent)->create();

    $this->actingAs($admin)->get('/settings/appearance')->assertInertia(fn (Assert $p) => $p
        ->where('sections', fn ($sections) => collect($sections)->contains(fn ($s) => $s['key'] === 'help' && $s['href'] === '/settings/help' && $s['labelKey'] === 'settings.section.help')));
    $this->actingAs($member)->get('/settings/appearance')->assertInertia(fn (Assert $p) => $p
        ->where('sections', fn ($sections) => ! collect($sections)->contains(fn ($s) => $s['key'] === 'help')));
});

it('returns the identical 403 as for other admin routes to members, and the login redirect to guests', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $reference = $this->actingAs($member)->get('/settings/audit');

    foreach ([['get', '/settings/help'], ['get', '/settings/help/tasks.visibility'], ['put', '/settings/help/tasks.visibility']] as [$method, $uri]) {
        $response = $this->actingAs($member)->{$method}($uri, ['texts' => ['en' => 'x']]);
        $response->assertForbidden();
        expect($response->status())->toBe($reference->status());
    }
    auth()->logout();
    $this->put('/settings/help/tasks.visibility', ['texts' => ['en' => 'x']])->assertRedirect();
    expect(HelpText::count())->toBe(0);
});

it('shows one topic with all languages', function () {
    $this->actingAs(helpAdmin())->getJson('/settings/help/tasks.recurrence')->assertOk()
        ->assertJsonPath('topic.key', 'tasks.recurrence')
        ->assertJsonStructure(['topic' => ['key', 'location' => ['area', 'screen', 'field'], 'titles' => ['sk', 'pl', 'en'], 'texts' => ['sk', 'pl', 'en'], 'defaults', 'edited']]);
    $this->actingAs(helpAdmin())->getJson('/settings/help/no.such.key')->assertNotFound();
});

it('saves an edit, marks only that language as edited, updates the cache and writes a content-free audit row', function () {
    $admin = helpAdmin();
    $help = app(HelpTexts::class);
    expect($help->payload('en')['topics']['tasks.recurrence']['text'])->not->toBe('My own words.');

    $this->actingAs($admin)->putJson('/settings/help/tasks.recurrence', ['texts' => ['en' => '  My own words.  ']])->assertOk()
        ->assertJsonPath('topic.texts.en', 'My own words.')
        ->assertJsonPath('topic.edited.en', true)
        ->assertJsonPath('topic.edited.sk', false)
        ->assertJsonPath('current.text', 'My own words.');

    $row = HelpText::find('tasks.recurrence');
    expect($row->text_en)->toBe('My own words.')
        ->and($row->edited_by)->toBe($admin->id)
        ->and($row->edited_at)->not->toBeNull()
        ->and($help->payload('en')['topics']['tasks.recurrence']['text'])->toBe('My own words.');

    $audit = AuditEntry::where('event', 'help_text_changed')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->meta)->toBe(['key' => 'tasks.recurrence', 'locales' => 'en'])
        ->and(json_encode($audit[0]->toArray()))->not->toContain('My own words');
});

it('resets a language to the default and clears its edited marker', function () {
    $admin = helpAdmin();
    $registry = app(HelpRegistry::class);
    $this->actingAs($admin)->putJson('/settings/help/tasks.recurrence', ['texts' => ['sk' => 'Moje slová.', 'pl' => 'Moje słowa.']])->assertOk();

    $this->actingAs($admin)->putJson('/settings/help/tasks.recurrence', ['reset' => ['sk']])->assertOk()
        ->assertJsonPath('topic.edited.sk', false)
        ->assertJsonPath('topic.edited.pl', true)
        ->assertJsonPath('topic.texts.sk', $registry->defaultText('tasks.recurrence', 'sk'));

    $meta = AuditEntry::where('event', 'help_text_changed')->orderByDesc('id')->first()->meta;
    expect($meta)->toBe(['key' => 'tasks.recurrence', 'locales' => 'sk']);
});

it('validates the body', function (array $body) {
    $this->actingAs(helpAdmin())->putJson('/settings/help/tasks.recurrence', $body)->assertUnprocessable();
    expect(HelpText::count())->toBe(0)->and(AuditEntry::where('event', 'help_text_changed')->count())->toBe(0);
})->with([
    'empty body' => [[]],
    'empty text' => [['texts' => ['en' => '']]],
    'blank text' => [['texts' => ['en' => '   ']]],
    'over 400 characters' => [['texts' => ['en' => str_repeat('a', 401)]]],
    'html' => [['texts' => ['en' => 'Click <b>here</b>']]],
    'angle bracket alone' => [['texts' => ['en' => 'a > b']]],
    'control character' => [['texts' => ['en' => "bad\x07text"]]],
    'unknown locale' => [['texts' => ['de' => 'Hallo']]],
    'texts not an array' => [['texts' => 'Hello']],
    'text not a string' => [['texts' => ['en' => ['x']]]],
    'reset unknown locale' => [['reset' => ['de']]],
    'reset duplicate' => [['reset' => ['sk', 'sk']]],
    'same locale edited and reset' => [['texts' => ['en' => 'Fine.'], 'reset' => ['en']]],
]);

it('accepts exactly 400 characters and keeps line breaks', function () {
    $text = str_repeat('é', 398)."\nx";
    $this->actingAs(helpAdmin())->putJson('/settings/help/tasks.recurrence', ['texts' => ['pl' => $text]])->assertOk();
    expect(mb_strlen(HelpText::find('tasks.recurrence')->text_pl))->toBe(400)->and(HelpText::find('tasks.recurrence')->text_pl)->toContain("\n");
});

it('refuses an unknown key without creating a row', function () {
    $this->actingAs(helpAdmin())->putJson('/settings/help/no.such.key', ['texts' => ['en' => 'x']])->assertNotFound();
    $this->actingAs(helpAdmin())->putJson('/settings/help/Bad_Key!', ['texts' => ['en' => 'x']])->assertNotFound();
    expect(HelpText::count())->toBe(0);
});

it('stores a text as data: markup-looking input is rejected and a stored text is never interpreted', function () {
    $this->actingAs(helpAdmin())->putJson('/settings/help/tasks.recurrence', ['texts' => ['en' => '<script>alert(1)</script>']])->assertUnprocessable();
});

it('keeps help texts when the editing user is removed', function () {
    $admin = helpAdmin();
    $this->actingAs($admin)->putJson('/settings/help/tasks.recurrence', ['texts' => ['en' => 'Kept after removal.']])->assertOk();

    $admin->delete();

    expect(HelpText::find('tasks.recurrence')->text_en)->toBe('Kept after removal.');
});

it('does not require a recent confirmation to edit (plain text, grants nothing)', function () {
    $route = app('router')->getRoutes()->getByName('settings.help.update');
    expect($route->gatherMiddleware())->toContain('access:admin')->not->toContain('access:recent');
});

it('shows the page error state instead of a server error when the registry cannot be read', function () {
    app()->instance(HelpRegistry::class, new HelpRegistry(sys_get_temp_dir().'/no-such-help-registry'));

    $this->actingAs(helpAdmin())->get('/settings/help')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('error', true)->where('topics', [])->where('orphans', []));
});
