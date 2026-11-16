<?php

use App\Help\HelpRegistry;
use App\Help\HelpTexts;
use App\Models\HelpText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('falls back to the registry default when the table is empty, for every registered key', function () {
    $registry = app(HelpRegistry::class);
    $payload = app(HelpTexts::class)->payload('sk');

    expect(array_keys($payload['topics']))->toBe($registry->keys())
        ->and($payload['locale'])->toBe('sk');
    foreach ($registry->keys() as $key) {
        expect($payload['topics'][$key]['text'])->toBe($registry->defaultText($key, 'sk'))->not->toBe('')
            ->and($payload['topics'][$key]['title'])->toBe($registry->title($key, 'sk'))->not->toBe('');
    }
});

it('uses the stored text and falls back per locale when a stored text is empty', function () {
    $help = app(HelpTexts::class);
    $help->sync();
    HelpText::find('tasks.visibility')->update(['text_en' => 'Stored English.', 'text_pl' => '']);
    $help->flush();

    expect($help->payload('en')['topics']['tasks.visibility']['text'])->toBe('Stored English.')
        ->and($help->payload('pl')['topics']['tasks.visibility']['text'])->toBe(app(HelpRegistry::class)->defaultText('tasks.visibility', 'pl'));
});

it('treats an unknown locale as English', function () {
    expect(app(HelpTexts::class)->payload('de')['locale'])->toBe('en');
});

it('shares the map for the active language with signed-in pages only', function () {
    $user = User::factory()->create();

    foreach (['sk', 'pl', 'en'] as $locale) {
        $this->withCookie('locale', $locale)->actingAs($user)->get('/settings/appearance')->assertInertia(fn (Assert $p) => $p
            ->where('help.locale', $locale)
            ->where('help.topics', fn ($topics) => $topics['tasks.visibility']['text'] === app(HelpRegistry::class)->defaultText('tasks.visibility', $locale)
                && $topics['tasks.visibility']['title'] === app(HelpRegistry::class)->title('tasks.visibility', $locale))
            ->has('help.version'));
    }
});

it('does not share help text with guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('help', null));
});

it('is the same for every member and carries no user data', function () {
    $a = User::factory()->create(['name' => 'Anna Secret']);
    $b = User::factory()->admin()->create(['name' => 'Boris Secret']);

    $one = $this->actingAs($a)->get('/settings/appearance')->viewData('page')['props']['help'];
    $two = $this->actingAs($b)->get('/settings/appearance')->viewData('page')['props']['help'];

    expect($one)->toEqual($two)
        ->and(json_encode($one))->not->toContain('Secret');
});

it('builds the payload from the cache: a second call runs no help query', function () {
    $help = app(HelpTexts::class);
    $help->payload('en');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $help->payload('en');
    $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'help_texts'));
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('changes the once-prop key after an edit so clients reload the texts', function () {
    $admin = User::factory()->admin()->create();
    $help = app(HelpTexts::class);

    $before = $this->actingAs($admin)->get('/settings/appearance')->viewData('page')['onceProps'];
    $help->update($admin, 'tasks.visibility', ['en' => 'Brand new text.'], []);
    $after = $this->actingAs($admin)->get('/settings/appearance')->viewData('page')['onceProps'];

    expect(array_keys($before))->not->toBe(array_keys($after))
        ->and(array_keys($after)[0] ?? '')->toStartWith('help-en-');
});
