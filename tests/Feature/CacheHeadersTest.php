<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

uses(RefreshDatabase::class);

it('marks the home page response no-store and private', function () {
    $cc = (string) $this->actingAs(User::factory()->create())->get('/')->headers->get('Cache-Control');
    expect($cc)->toContain('no-store')->toContain('private');
});

it('marks the locale redirect no-store', function () {
    $response = $this->post('/locale', ['locale' => 'pl']);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks the heartbeat redirect no-store', function () {
    $response = $this->actingAs(User::factory()->create())->post('/heartbeat');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks the login page no-store and private', function () {
    $cc = (string) $this->get('/login')->headers->get('Cache-Control');
    expect($cc)->toContain('no-store')->toContain('private');
});

it('keeps private, no-cache on the image route instead of no-store', function () {
    Storage::fake('local');
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $wish = Wish::factory()->withImage()->create(['owner_id' => $user->id]);
    Storage::disk('local')->put($wish->thumb_path, ImageFixtures::jpeg());

    $cc = (string) $this->actingAs($user)->get("/wishlist/wishes/{$wish->id}/image/thumb")->assertOk()->headers->get('Cache-Control');

    expect($cc)->toContain('private')->toContain('no-cache')->not->toContain('no-store');
});

it('still marks the image route error responses and other wishlist routes no-store', function () {
    Storage::fake('local');
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $wish = Wish::factory()->create(['owner_id' => $user->id]);

    $missing = (string) $this->actingAs($user)->get("/wishlist/wishes/{$wish->id}/image/thumb")->assertNotFound()->headers->get('Cache-Control');
    $list = (string) $this->actingAs($user)->get('/wishlist')->assertOk()->headers->get('Cache-Control');

    expect($missing)->toContain('no-store')->and($list)->toContain('no-store')->toContain('private');
});
