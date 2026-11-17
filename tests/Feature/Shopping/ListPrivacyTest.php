<?php

use App\Access\AccessLevel;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\User;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/**
 * Every list-scoped endpoint: method, URL builder and a valid body. A locked list with one item is used for the
 * routes that need it, so a legitimate actor would not fail for another reason.
 *
 * @return array<string, array{0: string, 1: Closure(int, int): string, 2: array<string, mixed>}>
 */
function listRoutes(): array
{
    return [
        'show' => ['GET', fn ($l, $i) => "/shopping/lists/$l", []],
        'update' => ['PUT', fn ($l, $i) => "/shopping/lists/$l", ['name' => 'Renamed']],
        'destroy' => ['DELETE', fn ($l, $i) => "/shopping/lists/$l", []],
        'lock' => ['PUT', fn ($l, $i) => "/shopping/lists/$l/lock", ['locked' => true]],
        'finish' => ['POST', fn ($l, $i) => "/shopping/lists/$l/finish", []],
        'copy' => ['POST', fn ($l, $i) => "/shopping/lists/$l/copy", ['name' => 'Copy', 'keep' => false]],
        'items.store' => ['POST', fn ($l, $i) => "/shopping/lists/$l/items", ['items' => [['name' => 'x', 'quantity' => '1']]]],
        'items.update' => ['PUT', fn ($l, $i) => "/shopping/lists/$l/items/$i", ['name' => 'x']],
        'items.destroy' => ['DELETE', fn ($l, $i) => "/shopping/lists/$l/items/$i", []],
        'items.state' => ['PUT', fn ($l, $i) => "/shopping/lists/$l/items/$i/state", ['state' => 'bought']],
    ];
}

function hiddenList(ListStatus $status = ListStatus::Locked): array
{
    $owner = F::user();
    $list = F::list($owner, status: $status);
    $item = F::item($list, ['state' => ItemState::Bought]);

    return [$owner, $list, $item];
}

/** The body of a 404 never names the list or its id (digits are masked: the page carries the same markup either way). */
function normalised404(string $body): string
{
    return (string) preg_replace('/\d+/', '#', $body);
}

it('answers a member with no role the same 404 as an unknown id on every list route', function (string $route) {
    [, $list, $item] = hiddenList();
    [$method, $url, $body] = listRoutes()[$route];
    $outsider = F::user();
    $adminEditor = User::factory()->admin()->create();
    F::setLevel($adminEditor, AccessLevel::Edit);

    foreach ([$outsider, $adminEditor] as $actor) {
        $real = $this->actingAs($actor)->call($method, $url($list->id, $item->id), $body);
        $unknown = $this->actingAs($actor)->call($method, $url(987654, 987655), $body);

        expect($real->getStatusCode())->toBe(404, "$route as {$actor->id}")
            ->and($unknown->getStatusCode())->toBe(404)
            ->and(normalised404((string) $real->getContent()))
            ->toBe(normalised404((string) $unknown->getContent()));
    }

    expect($list->fresh()->status)->toBe(ListStatus::Locked)->and($list->fresh()->name)->not->toBe('Renamed')
        ->and(ShoppingItem::query()->where('list_id', $list->id)->count())->toBe(1);
})->with(array_keys(listRoutes()));

it('lets a viewer open the list but never write', function (string $route) {
    $owner = F::user();
    $viewer = F::user(AccessLevel::View);
    $list = F::list($owner, status: ListStatus::Locked, viewers: [$viewer]);
    $item = F::item($list, ['state' => ItemState::Bought]);
    [$method, $url, $body] = listRoutes()[$route];

    $status = $this->actingAs($viewer)->call($method, $url($list->id, $item->id), $body)->getStatusCode();

    // A viewer holds Shopping view only, so the module gate already refuses every write (403) and the show is 200.
    expect($status)->toBe($route === 'show' ? 200 : 403);
})->with(array_keys(listRoutes()));

it('refuses a viewer with Shopping edit on a list where they are only a viewer', function (string $route) {
    $owner = F::user();
    $viewer = F::user();
    $list = F::list($owner, status: ListStatus::Locked, viewers: [$viewer]);
    $item = F::item($list, ['state' => ItemState::Bought]);
    [$method, $url, $body] = listRoutes()[$route];

    $status = $this->actingAs($viewer)->call($method, $url($list->id, $item->id), $body)->getStatusCode();

    // Copying is open to everyone who sees the list and holds Shopping edit (it creates a new list for the copier).
    expect($status)->toBe(match ($route) {
        'show' => 200, 'copy' => 303, default => 403
    });
    expect(ShoppingList::query()->whereKey($list->id)->value('status'))->toBe(ListStatus::Locked)
        ->and(ShoppingItem::query()->where('list_id', $list->id)->count())->toBe(1);
})->with(array_keys(listRoutes()));

it('gives a contributor 403 on lock, state, finish and delete and 409 on item edits while locked', function () {
    $owner = F::user();
    $mate = F::user();
    $list = F::list($owner, status: ListStatus::Locked, contributors: [$mate]);
    $item = F::item($list, ['state' => ItemState::Bought]);
    $call = fn (string $route) => $this->actingAs($mate)->call(listRoutes()[$route][0], listRoutes()[$route][1]($list->id, $item->id), listRoutes()[$route][2])->getStatusCode();

    expect($call('lock'))->toBe(403)
        ->and($call('items.state'))->toBe(403)
        ->and($call('finish'))->toBe(403)
        ->and($call('destroy'))->toBe(403)
        ->and($call('items.store'))->toBe(409)
        ->and($call('items.update'))->toBe(409)
        ->and($call('items.destroy'))->toBe(409)
        ->and($call('copy'))->toBe(303)
        ->and($call('show'))->toBe(200);
});

it('keeps another list of the same member hidden when ids are swapped between lists', function () {
    $me = F::user();
    $mine = F::list($me);
    $mineItem = F::item($mine);
    [, $theirs, $theirItem] = hiddenList(ListStatus::Open);

    // An item of a hidden list through my own list id, and the other way round.
    $this->actingAs($me)->put("/shopping/lists/{$mine->id}/items/{$theirItem->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($me)->put("/shopping/lists/{$theirs->id}/items/{$mineItem->id}", ['name' => 'x'])->assertNotFound();
    expect($theirItem->fresh()->name)->not->toBe('x');
});

it('does not put the name of a hidden list anywhere in the overview payload', function () {
    $owner = F::user();
    $outsider = F::user();
    $secret = F::list($owner);
    $secret->forceFill(['name' => 'Zebra-Surprise-Party'])->save();
    F::item($secret, ['name' => 'Hidden-Cake']);
    $done = F::list($owner, status: ListStatus::Done);
    $done->forceFill(['name' => 'Zebra-Archive'])->save();
    F::list($outsider);

    foreach (['/shopping', '/shopping?tab=archive'] as $url) {
        $response = $this->actingAs($outsider)->get($url)->assertOk();
        expect($response->getContent())->not->toContain('Zebra')->not->toContain('Hidden-Cake');
    }
});
