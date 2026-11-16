<?php

use App\Manual\ManualRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** @return list<string> */
function manualTopicIds(Assert $page): array
{
    return array_column($page->toArray()['props']['topics'], 'id');
}

it('sends guests to the sign-in page', function () {
    $this->get('/manual')->assertRedirect('/login');
});

it('renders the manual for a member without any module grant', function () {
    $this->actingAs(User::factory()->create())->get('/manual')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Manual/Index')
        ->has('categories')
        ->has('topics')
        ->where('topics.0.id', 'getting-started'));
});

it('hides the administrator guide and other admin-only topics from members', function () {
    $this->actingAs(User::factory()->create())->get('/manual')->assertInertia(function (Assert $page) {
        $ids = manualTopicIds($page);
        expect($ids)->not->toContain('admin')->and($ids)->toContain('tasks', 'health', 'who-sees-what');
        expect(array_column($page->toArray()['props']['categories'], 'id'))->not->toContain('admin');
    });
});

it('shows the administrator guide to an administrator', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/manual')->assertInertia(function (Assert $page) {
        expect(manualTopicIds($page))->toContain('admin');
        expect(array_column($page->toArray()['props']['categories'], 'id'))->toContain('admin');
    });
});

it('picks the locale from the language cookie', function (string $locale, string $title) {
    $this->actingAs(User::factory()->create())->withCookie('locale', $locale)->get('/manual')
        ->assertInertia(fn (Assert $page) => $page->where('topics.0.title', $title));
})->with([
    ['en', 'Getting started'],
    ['sk', 'Začíname'],
    ['pl', 'Pierwsze kroki'],
]);

it('sends only help text in its own props, with no internal metadata', function () {
    $user = User::factory()->create(['name' => 'Zuzana Testova', 'email' => 'zuzana.manual@example.test']);
    $props = $this->actingAs($user)->get('/manual')->assertOk()->viewData('page')['props'];
    $own = json_encode([$props['topics'], $props['categories']]);

    expect($own)->not->toContain('Zuzana')->and($own)->not->toContain('zuzana.manual');
    expect(array_keys($props['topics'][0]))->toBe(['id', 'category', 'order', 'title', 'summary', 'adminOnly', 'sections']);
    expect(json_encode([$props['topics'], $props['categories']]))->not->toContain('covers');
});

it('drops admin-only sections for members but keeps them for administrators', function () {
    $dir = sys_get_temp_dir().'/fh-manual-'.bin2hex(random_bytes(4));
    mkdir($dir.'/en', 0777, true);
    file_put_contents($dir.'/en/_categories.json', json_encode(['categories' => [['id' => 'c', 'title' => 'C']]]));
    file_put_contents($dir.'/en/mixed.json', json_encode([
        'id' => 'mixed', 'category' => 'c', 'order' => 1, 'title' => 'Mixed', 'summary' => 'S',
        'sections' => [
            ['id' => 'open', 'title' => 'Open', 'blocks' => [['type' => 'p', 'text' => 'x']]],
            ['id' => 'secret', 'title' => 'Secret', 'adminOnly' => true, 'blocks' => [['type' => 'p', 'text' => 'y']]],
        ],
    ]));

    try {
        $repo = new ManualRepository($dir);
        $member = $repo->for(User::factory()->make(['is_admin' => false]), 'en');
        $admin = $repo->for(User::factory()->make(['is_admin' => true]), 'en');

        expect(array_column($member['topics'][0]['sections'], 'id'))->toBe(['open']);
        expect(array_column($admin['topics'][0]['sections'], 'id'))->toBe(['open', 'secret']);
    } finally {
        foreach (glob($dir.'/en/*') as $file) {
            unlink($file);
        }
        rmdir($dir.'/en');
        rmdir($dir);
    }
});

it('falls back to English for a missing translation and survives a broken file', function () {
    $dir = sys_get_temp_dir().'/fh-manual-'.bin2hex(random_bytes(4));
    mkdir($dir.'/en', 0777, true);
    mkdir($dir.'/sk', 0777, true);
    file_put_contents($dir.'/en/_categories.json', json_encode(['categories' => [['id' => 'c', 'title' => 'C']]]));
    foreach (['a', 'b'] as $id) {
        file_put_contents($dir."/en/$id.json", json_encode(['id' => $id, 'category' => 'c', 'order' => 1, 'title' => "EN $id", 'sections' => []]));
    }
    file_put_contents($dir.'/sk/a.json', '{ not json');

    try {
        $topics = (new ManualRepository($dir))->for(User::factory()->make(['is_admin' => false]), 'sk')['topics'];
        expect(array_column($topics, 'title'))->toBe(['EN a', 'EN b']);
    } finally {
        foreach (array_merge(glob($dir.'/en/*'), glob($dir.'/sk/*')) as $file) {
            unlink($file);
        }
        rmdir($dir.'/en');
        rmdir($dir.'/sk');
        rmdir($dir);
    }
});
