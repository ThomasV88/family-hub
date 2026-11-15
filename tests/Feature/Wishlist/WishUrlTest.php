<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Wishlist\Catalog\WishPresenter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
});

function urlRows(int $n): array
{
    return array_map(fn ($i) => ['url' => "https://shop$i.example/p"], range(1, $n));
}

it('accepts 5 urls and rejects 6', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Five', 'urls' => urlRows(5)])->assertStatus(303);
    expect(Wish::query()->sole()->urls)->toHaveCount(5);

    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Six', 'urls' => urlRows(6)])->assertSessionHasErrors('urls');
    expect(Wish::count())->toBe(1);
});

it('rejects non-http schemes', function (string $url) {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Bad', 'urls' => [['url' => $url]]])
        ->assertSessionHasErrors(['urls.0.url' => 'wishlist.error.url_invalid']);
    expect(Wish::count())->toBe(0);
})->with(['javascript:alert(1)', 'data:text/html,<b>x</b>', 'file:///etc/passwd', 'ftp://example.com/a', 'example.com/no-scheme', 'https://', 'https://exa mple.com']);

it('drops blank rows and keeps an optional label up to 100 characters', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Blank', 'urls' => [['url' => ''], ['url' => '   ', 'label' => 'x'], ['url' => 'https://ok.example', 'label' => 'Shop']],
    ])->assertStatus(303);
    $wish = Wish::query()->sole();
    expect($wish->urls)->toHaveCount(1)->and($wish->urls[0]->label)->toBe('Shop');

    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Long', 'urls' => [['url' => 'https://ok.example', 'label' => str_repeat('a', 101)]],
    ])->assertSessionHasErrors('urls.0.label');
});

it('derives the display host lowercase without stripping www', function () {
    expect(WishPresenter::host('https://WWW.Example.COM/Path?q=1'))->toBe('www.example.com')
        ->and(WishPresenter::host('not a url'))->toBeNull();
});
