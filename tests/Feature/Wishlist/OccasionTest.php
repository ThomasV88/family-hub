<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\WishlistManager;
use App\Models\WishOccasion;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Pavol', 'birth_date' => '1980-03-15']);
    $this->other = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Olga', 'birth_date' => '1985-07-01']);
    $this->child = User::factory()->withPreset(Preset::Child)->create(['name' => 'Kid', 'birth_date' => '2015-05-05']);
    $this->admin = User::factory()->admin()->withGrant(Module::Wishlist, AccessLevel::Edit)->create();
    $this->dates = fn (int $id) => '/wishlist/members/'.$id.'/dates';
});

afterEach(fn () => Carbon::setTestNow());

it('renders the page props with day and month only for other members', function () {
    Carbon::setTestNow('2027-03-01 08:30:00');
    WishOccasion::factory()->nameday('sk', 'Ľubomír', 11, 9)->create(['owner_id' => $this->other->id]);

    $this->actingAs($this->parent)->get('/wishlist/occasions')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Wishlist/Occasions')
        ->where('today', '2027-03-01')
        ->where('can_add', true)
        ->has('upcoming')
        ->where('members.0.id', $this->parent->id)
        ->where('members.0.birth_date', '1980-03-15')
        ->where('members.0.can_edit_dates', true)
        ->where('members', function ($members) {
            $kid = collect($members)->firstWhere('id', $this->child->id);

            return $kid['birth'] === ['month' => 5, 'day' => 5] && $kid['birth_date'] === null && $kid['can_edit_dates'] === false;
        }));

    $json = $this->actingAs($this->parent)->get('/wishlist/occasions')->getContent();
    expect($json)->not->toContain('1985-07-01')->not->toContain('2015-05-05');
});

it('lets a member, a manager and an admin save dates but not an unrelated member', function () {
    $payload = ['birth_date' => '2015-05-06', 'nameday' => ['calendar' => 'sk', 'name' => 'Ľubomír', 'month' => 8, 'day' => 13]];

    $this->actingAs($this->other)->putJson(($this->dates)($this->child->id), $payload)->assertForbidden();

    $this->actingAs($this->child)->putJson(($this->dates)($this->child->id), $payload)->assertRedirect()
        ->assertSessionHas('toast.key', 'occasions.toast.dates_saved');
    expect($this->child->fresh()->birth_date->format('Y-m-d'))->toBe('2015-05-06');

    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->other->id]);
    $this->actingAs($this->other)->putJson(($this->dates)($this->child->id), ['birth_date' => '2015-05-07'])->assertRedirect();
    expect($this->child->fresh()->birth_date->format('Y-m-d'))->toBe('2015-05-07');

    $this->actingAs($this->admin)->putJson(($this->dates)($this->parent->id), ['birth_date' => '1980-03-16'])->assertRedirect();
    expect($this->parent->fresh()->birth_date->format('Y-m-d'))->toBe('1980-03-16');

    // An admin without the wishlist edit grant stays out (middleware), and so does a grant without admin or manager role.
    $plainAdmin = User::factory()->admin()->create();
    $this->actingAs($plainAdmin)->putJson(($this->dates)($this->parent->id), ['birth_date' => '1980-03-17'])->assertForbidden();
});

it('stores a name day snapshot, replaces it and clears it', function () {
    $this->actingAs($this->parent)->putJson(($this->dates)($this->parent->id), ['nameday' => ['calendar' => 'sk', 'name' => 'Ľubomír', 'month' => 8, 'day' => 13]])->assertRedirect();
    $row = WishOccasion::query()->sole();
    expect([$row->kind, $row->calendar, $row->name, $row->month, $row->day, $row->owner_id])->toBe(['nameday', 'sk', 'Ľubomír', 8, 13, $this->parent->id]);

    $this->actingAs($this->parent)->putJson(($this->dates)($this->parent->id), ['nameday' => ['calendar' => 'pl', 'name' => 'Anna', 'month' => 7, 'day' => 26]])->assertRedirect();
    expect(WishOccasion::query()->sole()->name)->toBe('Anna');

    $this->actingAs($this->parent)->putJson(($this->dates)($this->parent->id), ['nameday' => null])->assertRedirect();
    expect(WishOccasion::count())->toBe(0);
});

it('rejects invalid dates and name days without changing anything', function (array $payload, string $key) {
    $this->actingAs($this->parent)->putJson(($this->dates)($this->parent->id), $payload)
        ->assertStatus(422)->assertJsonValidationErrors([$key]);

    expect($this->parent->fresh()->birth_date->format('Y-m-d'))->toBe('1980-03-15')->and(WishOccasion::count())->toBe(0);
})->with([
    'future birth' => [['birth_date' => '2999-01-01'], 'birth_date'],
    'bad birth' => [['birth_date' => '2000-02-30'], 'birth_date'],
    'unknown name' => [['nameday' => ['calendar' => 'sk', 'name' => 'Nonexistent', 'month' => 1, 'day' => 1]], 'nameday.day'],
    'wrong day for name' => [['nameday' => ['calendar' => 'sk', 'name' => 'Ľubomír', 'month' => 1, 'day' => 1]], 'nameday.day'],
    'bad calendar' => [['nameday' => ['calendar' => 'de', 'name' => 'Hans', 'month' => 1, 'day' => 1]], 'nameday.calendar'],
]);

it('answers 403 alike for unknown ids, non-members and every caller', function () {
    $outsider = User::factory()->create();

    foreach ([999999, $outsider->id] as $id) {
        foreach ([$this->parent, $this->other] as $actor) {
            $this->actingAs($actor)->putJson(($this->dates)($id), ['birth_date' => '1990-01-01'])->assertForbidden();
        }
    }
    expect($outsider->fresh()->birth_date)->toBeNull();
});

it('creates custom occasions for the family or one member', function () {
    $this->actingAs($this->child)->post('/wishlist/occasions', ['title' => 'Wedding day', 'date' => '2027-06-12', 'yearly' => true])
        ->assertRedirect('/wishlist/occasions')->assertSessionHas('toast.key', 'occasions.toast.saved');
    $this->actingAs($this->child)->post('/wishlist/occasions', ['title' => 'Trip', 'date' => '2027-08-01', 'yearly' => false, 'owner_id' => $this->other->id])->assertRedirect();

    $a = WishOccasion::query()->where('title', 'Wedding day')->sole();
    $b = WishOccasion::query()->where('title', 'Trip')->sole();
    expect([$a->kind, $a->owner_id, $a->year, $a->month, $a->day, $a->created_by_id])->toBe(['custom', null, null, 6, 12, $this->child->id])
        ->and([$b->owner_id, $b->year])->toBe([$this->other->id, 2027]);
});

it('validates custom occasions', function (array $payload, string $key, string $message) {
    $response = $this->actingAs($this->parent)->postJson('/wishlist/occasions', $payload + ['title' => 'Ok', 'date' => '2027-06-12', 'yearly' => true])
        ->assertStatus(422)->assertJsonValidationErrors([$key]);
    expect($response->json("errors.$key.0"))->toBe($message);
    expect(WishOccasion::count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title', 'occasions.error.title_required'],
    'bad date' => [['date' => '2027-02-30'], 'date', 'occasions.error.date_invalid'],
    'no date' => [['date' => ''], 'date', 'occasions.error.date_invalid'],
    'owner not a member' => [['owner_id' => 999999], 'owner_id', 'occasions.error.save_failed'],
]);

it('lets only the creator or an admin change a custom occasion', function () {
    $occasion = WishOccasion::factory()->create(['title' => 'Mine']);
    $occasion->forceFill(['created_by_id' => $this->parent->id])->save();
    $body = ['title' => 'Renamed', 'date' => '2027-09-09', 'yearly' => true];

    $this->actingAs($this->other)->patch('/wishlist/occasions/'.$occasion->id, $body)->assertForbidden();
    $this->actingAs($this->other)->delete('/wishlist/occasions/'.$occasion->id)->assertForbidden();

    $this->actingAs($this->parent)->patch('/wishlist/occasions/'.$occasion->id, $body)->assertRedirect()
        ->assertSessionHas('toast.key', 'occasions.toast.saved');
    expect($occasion->fresh()->title)->toBe('Renamed')->and($occasion->fresh()->month)->toBe(9);

    $this->actingAs($this->admin)->delete('/wishlist/occasions/'.$occasion->id)->assertRedirect()
        ->assertSessionHas('toast.key', 'occasions.toast.deleted');
    expect(WishOccasion::count())->toBe(0);
});

it('does not expose name day rows to the custom routes', function () {
    $row = WishOccasion::factory()->nameday()->create(['owner_id' => $this->parent->id]);

    $this->actingAs($this->parent)->patch('/wishlist/occasions/'.$row->id, ['title' => 'x', 'date' => '2027-01-01', 'yearly' => true])->assertNotFound();
    $this->actingAs($this->parent)->delete('/wishlist/occasions/'.$row->id)->assertNotFound();
    expect(WishOccasion::count())->toBe(1);
});

it('searches name days with limits and validation', function () {
    $this->actingAs($this->parent)->getJson('/wishlist/namedays?calendar=sk&q=lub')->assertOk()
        ->assertJsonStructure(['results' => [['name', 'month', 'day']]]);
    $results = $this->actingAs($this->parent)->getJson('/wishlist/namedays?calendar=pl&q=an')->json('results');
    expect(count($results))->toBeLessThanOrEqual(8)->and(count($results))->toBeGreaterThan(0);

    expect($this->actingAs($this->parent)->getJson('/wishlist/namedays?calendar=sk&q=l')->json('results'))->toBe([]);
    $this->actingAs($this->parent)->getJson('/wishlist/namedays?calendar=sk&q='.str_repeat('a', 41))->assertStatus(422);
    $this->actingAs($this->parent)->getJson('/wishlist/namedays?calendar=de&q=lub')->assertStatus(422);
});
