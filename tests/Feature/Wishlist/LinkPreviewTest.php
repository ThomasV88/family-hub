<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishUrl;
use App\Wishlist\Catalog\FetchLinkPreview;
use App\Wishlist\Fetch\SafeFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDnsResolver;
use Tests\Support\ImageFixtures;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->other = User::factory()->withPreset(Preset::Parent)->create();
    $this->grand = User::factory()->withPreset(Preset::Guest)->create();
});

function previewFetcher(array $responses): MockHandler
{
    $mock = new MockHandler($responses);
    app()->instance(SafeFetcher::class, new SafeFetcher(
        new FakeDnsResolver(['shop.example' => ['93.184.216.34'], 'cdn.example' => ['93.184.216.35']]),
        null,
        $mock,
    ));

    return $mock;
}

function runPreview(string $url, string $token = 'tok', int $user = 1): array
{
    app()->call([new FetchLinkPreview($token, $url, $user), 'handle']);

    return Cache::get(FetchLinkPreview::key($token));
}

const FULL_PAGE = '<html><head><title>Lamp</title><meta name="description" content="A nice lamp"><meta property="og:image" content="https://cdn.example/p.jpg"></head></html>';

it('reads title, description and picture of a full page', function () {
    previewFetcher([new Response(200, [], FULL_PAGE), new Response(200, [], ImageFixtures::jpeg(800, 600))]);
    $r = runPreview('https://shop.example/lamp');
    expect($r)->title->toBe('Lamp')->description->toBe('A nice lamp')->image->toBeTrue()->failures->toBe([])
        ->and($r['thumb'])->not->toBeNull();
});

it('lists every field of a page without tags as no_tags', function () {
    previewFetcher([new Response(200, [], '<html><body>nothing</body></html>')]);
    expect(runPreview('https://shop.example/x')['failures'])->toBe(['title' => 'no_tags', 'description' => 'no_tags', 'image' => 'no_tags']);
});

it('reports only the missing piece', function () {
    previewFetcher([new Response(200, [], '<title>T</title>')]);
    expect(runPreview('https://shop.example/x')['failures'])->toBe(['description' => 'no_tags', 'image' => 'no_tags']);
});

it('maps an unreachable page and a timeout', function () {
    previewFetcher([new Response(500)]);
    expect(runPreview('https://shop.example/x')['failures']['title'])->toBe('unreachable');
    previewFetcher([new GuzzleHttp\Exception\ConnectException('timed out', new GuzzleHttp\Psr7\Request('GET', 'https://shop.example/x'), null, ['errno' => 28])]);
    expect(runPreview('https://shop.example/x', 't2')['failures']['title'])->toBeIn(['timeout', 'unreachable']);
});

it('refuses loopback and metadata addresses as blocked without sending a request', function (string $url) {
    $mock = previewFetcher([new Response(200, [], FULL_PAGE)]);
    expect(runPreview($url)['failures']['title'])->toBe('blocked')->and($mock->count())->toBe(1);
})->with(['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://localhost/']);

it('logs no address and no page text', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    previewFetcher([new Response(200, [], FULL_PAGE), new Response(200, [], ImageFixtures::jpeg())]);
    runPreview('https://shop.example/secret-path');
    $text = implode("\n", $logged);
    expect($text)->not->toContain('shop.example')->not->toContain('Lamp')->not->toContain('nice lamp');
});

it('starts a preview for an editor, queues the job and writes nothing to wishes', function () {
    Queue::fake();
    $res = $this->actingAs($this->parent)->postJson('/wishlist/link-preview', ['url' => 'https://shop.example/a'])->assertOk();
    Queue::assertPushed(FetchLinkPreview::class, fn ($j) => $j->url === 'https://shop.example/a' && $j->userId === $this->parent->id);
    expect(Wish::query()->count())->toBe(0)->and(WishUrl::query()->count())->toBe(0);
    $this->actingAs($this->parent)->getJson('/wishlist/link-preview/'.$res->json('token'))->assertOk()->assertJsonPath('status', 'pending');
});

it('serves the result and thumbnail only to the asking user', function () {
    previewFetcher([new Response(200, [], FULL_PAGE), new Response(200, [], ImageFixtures::jpeg(800, 600))]);
    $token = $this->actingAs($this->parent)->postJson('/wishlist/link-preview', ['url' => 'https://shop.example/lamp'])->json('token');
    $this->actingAs($this->parent)->getJson("/wishlist/link-preview/{$token}")
        ->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('title', 'Lamp')->assertJsonPath('image', true);
    $this->actingAs($this->parent)->get("/wishlist/link-preview/{$token}/image")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->other)->getJson("/wishlist/link-preview/{$token}")->assertNotFound();
    $this->actingAs($this->other)->get("/wishlist/link-preview/{$token}/image")->assertNotFound();
    $this->actingAs($this->parent)->getJson('/wishlist/link-preview/'.str_repeat('a', 40))->assertNotFound();
});

it('refuses users without wishlist edit, bad urls, and throttles at 15 a minute', function () {
    Queue::fake();
    $this->actingAs($this->grand)->postJson('/wishlist/link-preview', ['url' => 'https://shop.example/a'])->assertForbidden();
    foreach (['ftp://x.example/a', 'javascript:alert(1)', 'not a url', ''] as $bad) {
        $this->actingAs($this->parent)->postJson('/wishlist/link-preview', ['url' => $bad])->assertStatus(422);
    }
    for ($i = 0; $i < 15; $i++) {
        $this->actingAs($this->parent)->postJson('/wishlist/link-preview', ['url' => 'https://shop.example/a']);
    }
    $this->actingAs($this->parent)->postJson('/wishlist/link-preview', ['url' => 'https://shop.example/a'])->assertStatus(429);
});
