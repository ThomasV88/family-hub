<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
});

it('creates a fully specified wish on the own list', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Lego set', 'notes' => 'The big one', 'price' => '12,50', 'currency' => 'EUR',
        'size_notes' => 'Blue', 'priority' => 'top',
        'urls' => [['url' => 'https://a.example/x', 'label' => 'Shop A'], ['url' => 'https://b.example/y']],
    ])->assertStatus(303);

    $wish = Wish::query()->sole();
    expect($wish->price_minor)->toBe(1250)
        ->and($wish->owner_id)->toBe($this->parent->id)
        ->and($wish->priority->value)->toBe(2)
        ->and($wish->urls)->toHaveCount(2)
        ->and($wish->urls->pluck('url')->all())->toBe(['https://a.example/x', 'https://b.example/y']);
});
