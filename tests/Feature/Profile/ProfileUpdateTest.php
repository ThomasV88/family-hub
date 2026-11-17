<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Anna Nova', 'email' => 'anna@example.test']);
    $this->other = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->child()->create(['name' => 'Kid']);
});

it('shows the own profile with only the listed subject fields', function () {
    $this->actingAs($this->parent)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('subject.id', $this->parent->id)
            ->where('subject.name', 'Anna Nova')
            ->where('subject.email', 'anna@example.test')
            ->where('subject.photoVersion', null)
            ->where('subject.isSelf', true)
            ->where('subject.isChild', false)
            ->where('nameMax', 60)
        );
    $props = $this->actingAs($this->parent)->get('/profile')->viewData('page')['props'];
    expect(array_keys($props['subject']))->toEqualCanonicalizing(['id', 'name', 'email', 'photoVersion', 'isSelf', 'isChild']);
});

it('sends a guest to the login page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

it('trims and collapses the name', function () {
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => "  Anna   Nov\u{00E1} "])
        ->assertStatus(303)->assertSessionHas('toast');
    expect($this->parent->fresh()->name)->toBe("Anna Nov\u{00E1}");
});

it('never changes the e-mail', function () {
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => 'Anna', 'email' => 'x@example.test'])
        ->assertSessionHasErrors('email');
    expect($this->parent->fresh()->email)->toBe('anna@example.test');
});

it('refuses bad names', function (string $name) {
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => $name])
        ->assertSessionHasErrors('name');
    expect($this->parent->fresh()->name)->toBe('Anna Nova');
})->with([
    'too long' => [str_repeat('a', 61)],
    'empty' => [''],
    'whitespace only' => ['   '],
    'tab inside is collapsed but a control char is refused' => ["Ann\x07a"],
    'bidi override' => ["Anna\u{202E}"],
]);

it('treats a tab as whitespace', function () {
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => "Anna\tNova2"])->assertStatus(303);
    expect($this->parent->fresh()->name)->toBe('Anna Nova2');
});

it('applies the length limit only when the name changes', function () {
    $long = str_repeat('b', 80);
    $this->parent->forceFill(['name' => $long])->save();

    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => $long])->assertStatus(303);
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => str_repeat('c', 80)])
        ->assertSessionHasErrors('name');
    expect($this->parent->fresh()->name)->toBe($long);
});

it('refuses renaming another adult with the same 404 as an unknown id', function () {
    $this->actingAs($this->parent)->put("/people/{$this->other->id}/name", ['name' => 'Hacked'])->assertNotFound();
    $this->actingAs($this->parent)->put('/people/999999/name', ['name' => 'Hacked'])->assertNotFound();
});

it('lets an admin rename a child and audits it without the name text', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put("/people/{$this->child->id}/name", ['name' => 'Little Kid'])->assertStatus(303);

    expect($this->child->fresh()->name)->toBe('Little Kid');
    $row = AuditEntry::query()->where('event', 'profile_changed_by_other')->sole();
    expect($row->meta)->toBe(['field' => 'name'])
        ->and(json_encode($row->meta))->not->toContain('Little');
});

it('lets a designated manager rename a child but writes no audit row for self edits', function () {
    $manager = User::factory()->withPreset(Preset::Parent)->create();
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $manager->id]);
    $this->actingAs($manager)->put("/people/{$this->child->id}/name", ['name' => 'Kiddo'])->assertStatus(303);
    $this->actingAs($this->parent)->put("/people/{$this->parent->id}/name", ['name' => 'Anna Two'])->assertStatus(303);

    expect(AuditEntry::query()->where('event', 'profile_changed_by_other')->count())->toBe(1);
});
