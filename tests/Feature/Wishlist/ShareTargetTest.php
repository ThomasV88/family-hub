<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishUrl;
use Illuminate\Support\Facades\Log;

it('sends a guest to login and keeps the share query for after login', function () {
    $url = '/wishlist/share?title=Shoes&url='.urlencode('https://shop.example/p');
    $this->get($url)->assertRedirect('/login');
    expect(session('url.intended'))->toContain('/wishlist/share?title=Shoes&url=https%3A%2F%2Fshop.example%2Fp');

    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(session('url.intended'));
});

it('redirects to the new-wish form and exposes the prefill on that one render', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();

    $this->actingAs($user)->get('/wishlist/share?'.http_build_query(['title' => 'Shoes', 'text' => 'x', 'url' => 'https://shop.example/p']))
        ->assertRedirect('/wishlist?wish=new');

    $this->actingAs($user)->get('/wishlist?wish=new')
        ->assertInertia(fn ($page) => $page->where('share_prefill', ['title' => 'Shoes', 'url' => 'https://shop.example/p']));

    $this->actingAs($user)->get('/wishlist?wish=new')
        ->assertInertia(fn ($page) => $page->where('share_prefill', null));
});

it('finds the link in the text field', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->actingAs($user)->get('/wishlist/share?'.http_build_query(['text' => 'Nice shoes https://shop.example/p/1']))->assertRedirect('/wishlist?wish=new');
    $this->actingAs($user)->get('/wishlist?wish=new')
        ->assertInertia(fn ($page) => $page->where('share_prefill', ['title' => 'Nice shoes', 'url' => 'https://shop.example/p/1']));
});

it('drops hostile input and tolerates array params', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->actingAs($user)->get('/wishlist/share?url=javascript:alert(1)&title[]=x&text[a]=b')->assertRedirect('/wishlist?wish=new');
    $this->actingAs($user)->get('/wishlist?wish=new')
        ->assertInertia(fn ($page) => $page->where('share_prefill', ['title' => '', 'url' => null]));
});

it('refuses users without wishlist edit', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $this->actingAs($guest)->get('/wishlist/share?url=https%3A%2F%2Fshop.example%2Fp')->assertForbidden();
    $none = User::factory()->create();
    $this->actingAs($none)->get('/wishlist/share?url=https%3A%2F%2Fshop.example%2Fp')->assertForbidden();
});

it('writes nothing and logs nothing', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    Log::spy();

    $this->actingAs($user)->get('/wishlist/share?title=Shoes&url='.urlencode('https://shop.example/secret-path'))->assertRedirect('/wishlist?wish=new');

    expect(Wish::count())->toBe(0)->and(WishUrl::count())->toBe(0);
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('debug');
});
