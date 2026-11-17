<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ImageFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('local');
    $this->manager = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Anna']);
    $this->unrelated = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->child()->withPreset(Preset::Child)->create(['name' => 'Kid']);
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->manager->id]);
});

it('shows a managed child to its manager and to an admin, with the child subject', function () {
    $admin = User::factory()->admin()->create();

    foreach ([$this->manager, $admin] as $viewer) {
        $this->actingAs($viewer)->get("/profile/people/{$this->child->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Profile/Edit')
            ->where('subject.id', $this->child->id)
            ->where('subject.name', 'Kid')
            ->where('subject.email', null)
            ->where('subject.isSelf', false)
            ->where('subject.isChild', true));
    }
});

it('answers one 404 for an unrelated member, an adult, the own id and an unknown id', function () {
    $this->actingAs($this->unrelated)->get("/profile/people/{$this->child->id}")->assertNotFound();
    $this->actingAs($this->manager)->get("/profile/people/{$this->unrelated->id}")->assertNotFound();
    $this->actingAs($this->manager)->get("/profile/people/{$this->manager->id}")->assertNotFound();
    $this->actingAs($this->manager)->get('/profile/people/999999')->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get("/profile/people/{$this->unrelated->id}")->assertNotFound();
});

it('sends a guest to the login page', function () {
    $this->get("/profile/people/{$this->child->id}")->assertRedirect('/login');
});

it('lists managed children on the own profile and nobody else', function () {
    $this->actingAs($this->manager)->get('/profile')->assertInertia(fn (Assert $p) => $p
        ->has('managedPeople', 1)
        ->where('managedPeople.0.id', $this->child->id)
        ->where('managedPeople.0.name', 'Kid')
        ->where('managedPeople.0.photoVersion', null));
    $props = $this->actingAs($this->manager)->get('/profile')->viewData('page')['props'];
    expect(array_keys($props['managedPeople'][0]))->toEqualCanonicalizing(['id', 'name', 'photoVersion']);

    $this->actingAs($this->unrelated)->get('/profile')->assertInertia(fn (Assert $p) => $p->where('managedPeople', []));
});

it('lets the manager rename the child and change its photo', function () {
    $this->actingAs($this->manager)->put("/people/{$this->child->id}/name", ['name' => 'Kiddo'])->assertStatus(303);
    expect($this->child->fresh()->name)->toBe('Kiddo');

    $upload = UploadedFile::fake()->createWithContent('p.jpg', ImageFixtures::jpeg());
    $this->actingAs($this->manager)->post("/people/{$this->child->id}/photo", ['photo' => $upload])->assertStatus(303);
    expect($this->child->fresh()->photo_path)->not->toBeNull()
        ->and(AuditEntry::query()->where('event', 'profile_changed_by_other')->count())->toBe(2);
});

it('refuses an unrelated member the name and photo of the child', function () {
    $this->actingAs($this->unrelated)->put("/people/{$this->child->id}/name", ['name' => 'Hacked'])->assertNotFound();
    $this->actingAs($this->unrelated)->delete("/people/{$this->child->id}/photo")->assertNotFound();
    expect($this->child->fresh()->name)->toBe('Kid');
});

it('tells a member who manages their own wish list, and never lists a child manager or a stranger', function () {
    $converted = User::factory()->withPreset(Preset::Child)->create(['name' => 'Ema']);
    WishlistManager::factory()->create(['owner_id' => $converted->id, 'manager_id' => $this->manager->id]);
    WishlistManager::factory()->create(['owner_id' => $this->unrelated->id, 'manager_id' => $this->manager->id]);

    $this->actingAs($converted)->get('/profile')->assertInertia(fn (Assert $p) => $p
        ->where('wishlistManagers', [$this->manager->name]));
    // The manager's own list has no managers; a child's page (another subject) carries no notice data at all.
    $this->actingAs($this->manager)->get('/profile')->assertInertia(fn (Assert $p) => $p->where('wishlistManagers', []));
    $this->actingAs($this->manager)->get("/profile/people/{$this->child->id}")->assertInertia(fn (Assert $p) => $p->missing('wishlistManagers'));
});
