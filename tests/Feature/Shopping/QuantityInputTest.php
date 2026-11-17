<?php

use App\Models\ShoppingItem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->me = F::user();
    $this->list = F::list($this->me);
});

it('accepts a decimal comma and surrounding spaces', function (string $typed, string $stored) {
    $this->actingAs($this->me)->post("/shopping/lists/{$this->list->id}/items", ['items' => [['name' => 'Rice', 'quantity' => $typed]]])
        ->assertStatus(303)->assertSessionHasNoErrors();

    expect((string) ShoppingItem::query()->where('list_id', $this->list->id)->value('quantity'))->toBe($stored);
})->with([
    ['0,5', '0.50'],
    [' 1,25 ', '1.25'],
    ['2', '2.00'],
    ['999,99', '999.99'],
    ['0.01', '0.01'],
]);

it('refuses exponents, zero, negatives, too large and too precise quantities', function (string $typed) {
    $this->actingAs($this->me)->post("/shopping/lists/{$this->list->id}/items", ['items' => [['name' => 'Rice', 'quantity' => $typed]]])
        ->assertSessionHasErrors(['items.0.quantity' => 'shopping.error.quantity']);

    expect(ShoppingItem::query()->count())->toBe(0);
})->with(['1e3', '0', '0,00', '-1', '1000', '1,234', 'abc', '1,2,3', '0x10']);

it('applies the same rule when editing an item', function () {
    $item = F::item($this->list);
    $url = "/shopping/lists/{$this->list->id}/items/{$item->id}";

    $this->actingAs($this->me)->put($url, ['quantity' => '0,5'])->assertStatus(303);
    expect((string) $item->fresh()->quantity)->toBe('0.50');
    $this->actingAs($this->me)->put($url, ['quantity' => '1e3'])->assertSessionHasErrors(['quantity' => 'shopping.error.quantity']);
    $this->actingAs($this->me)->put($url, ['quantity' => ''])->assertSessionHasErrors('quantity');
    expect((string) $item->fresh()->quantity)->toBe('0.50');
});
