<?php

use App\Help\HelpRegistry;
use App\Help\HelpTexts;
use App\Models\HelpText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** A throwaway registry in a temp directory, so a test can change a default or remove a key. @param array<string, string> $texts key => English default */
function helpFixtureRegistry(array $texts, array $placement = []): HelpRegistry
{
    $dir = sys_get_temp_dir().'/help-fixture-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $topics = [];
    foreach (array_keys($texts) as $key) {
        $topics[] = ($placement[$key] ?? ['area' => 'tasks', 'screen' => 'task_panel']) + ['key' => $key, 'field' => 'f_'.str_replace('.', '_', $key)];
    }
    file_put_contents("{$dir}/topics.json", json_encode(['version' => 1, 'topics' => $topics]));
    foreach (['en', 'sk', 'pl'] as $locale) {
        $entries = [];
        foreach ($texts as $key => $text) {
            $entries[$key] = ['title' => "Title {$key}", 'text' => $locale === 'en' ? $text : "{$locale}: {$text}"];
        }
        file_put_contents("{$dir}/{$locale}.json", json_encode(['areas' => ['tasks' => 'Tasks'], 'screens' => ['task_panel' => 'Task panel'], 'topics' => $entries]));
    }
    app()->instance(HelpRegistry::class, new HelpRegistry($dir));

    return app(HelpRegistry::class);
}

it('inserts every registry key with the default texts and reports nothing else', function () {
    $registry = app(HelpRegistry::class);

    $this->artisan('help:sync')->assertSuccessful();

    expect(HelpText::count())->toBe(count($registry->keys()));
    $row = HelpText::find('tasks.visibility');
    expect($row->text_en)->toBe($registry->defaultText('tasks.visibility', 'en'))
        ->and($row->text_sk)->toBe($registry->defaultText('tasks.visibility', 'sk'))
        ->and($row->text_pl)->toBe($registry->defaultText('tasks.visibility', 'pl'))
        ->and([$row->area, $row->screen, $row->field])->toBe(['tasks', 'task_panel', 'visibility'])
        ->and([$row->edited_sk, $row->edited_pl, $row->edited_en])->toBe([false, false, false]);
});

it('is idempotent: a second run changes nothing', function () {
    $help = app(HelpTexts::class);
    $first = $help->sync();
    $snapshot = HelpText::orderBy('key')->get()->toArray();

    $second = $help->sync();

    expect($first['inserted'])->toBe(count(app(HelpRegistry::class)->keys()))
        ->and($second['inserted'])->toBe(0)
        ->and($second['refreshed'])->toBe(0)
        ->and($second['unchanged'])->toBe($first['inserted'])
        ->and(HelpText::orderBy('key')->get()->toArray())->toBe($snapshot);
});

it('refreshes the default of an unedited locale and the placement, and keeps an edited one', function () {
    helpFixtureRegistry(['demo.one' => 'First default.']);
    $help = app(HelpTexts::class);
    $help->sync();

    $admin = User::factory()->admin()->create();
    $help->update($admin, 'demo.one', ['en' => 'Edited by an admin.'], []);

    // A new release changes the defaults and moves the tip to another screen.
    helpFixtureRegistry(['demo.one' => 'Second default.'], ['demo.one' => ['area' => 'tasks', 'screen' => 'task_board']]);
    $result = app(HelpTexts::class)->sync();

    $row = HelpText::find('demo.one');
    expect($row->text_en)->toBe('Edited by an admin.')
        ->and($row->edited_en)->toBeTrue()
        ->and($row->text_sk)->toBe('sk: Second default.')
        ->and($row->text_pl)->toBe('pl: Second default.')
        ->and($row->screen)->toBe('task_board')
        ->and($result['refreshed'])->toBe(1)
        ->and($result['kept_edited'])->toBe(1);
});

it('reports orphan keys and never deletes them', function () {
    helpFixtureRegistry(['demo.one' => 'One.', 'demo.two' => 'Two.']);
    app(HelpTexts::class)->sync();

    helpFixtureRegistry(['demo.one' => 'One.']);
    $result = app(HelpTexts::class)->sync();

    expect($result['orphans'])->toBe(['demo.two'])
        ->and(HelpText::find('demo.two'))->not->toBeNull();

    $this->artisan('help:sync')->expectsOutputToContain('Orphan help key')->assertSuccessful();
});

it('restores a deleted row on the next sync and flushes the cache', function () {
    $help = app(HelpTexts::class);
    $help->sync();
    $before = $help->version();
    HelpText::find('tasks.visibility')->delete();

    usleep(2000);
    Artisan::call('help:sync');

    expect(HelpText::find('tasks.visibility'))->not->toBeNull()
        ->and($help->version())->toBeGreaterThan($before);
});

it('is called by the staging seed path, so a reseeded staging always has the tips', function () {
    Storage::fake('medical');
    expect(HelpText::count())->toBe(0);

    $this->artisan('staging:seed', ['--force' => true])->expectsOutputToContain('help:sync inserted')->assertSuccessful();

    expect(HelpText::count())->toBe(count(app(HelpRegistry::class)->keys()));
});
