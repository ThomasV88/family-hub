<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->other = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->child()->create();
});

function jpegUpload(string $bytes, string $name = 'me.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('re-encodes a self upload to fresh JPEGs without EXIF or GPS', function () {
    $this->actingAs($this->parent)
        ->post("/people/{$this->parent->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpegWithExif(6, 800, 600))])
        ->assertStatus(303)->assertSessionHas('toast');

    $user = $this->parent->fresh();
    expect($user->photo_version)->toBe(1);
    foreach ([$user->photo_path, $user->photo_thumb_path] as $path) {
        $bytes = Storage::disk('local')->get($path);
        expect(str_starts_with($bytes, "\xFF\xD8"))->toBeTrue()
            ->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and(str_contains($bytes, "\xFF\xE1"))->toBeFalse();
    }
    [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($user->photo_path));
    [$tw, $th] = getimagesizefromstring(Storage::disk('local')->get($user->photo_thumb_path));
    expect(max($w, $h))->toBeLessThanOrEqual(512)->and(max($tw, $th))->toBeLessThanOrEqual(192);
    expect(AuditEntry::query()->where('event', 'profile_changed_by_other')->count())->toBe(0);
});

it('refuses an upload for someone else with 403 before the body is read, even when empty', function () {
    $this->actingAs($this->other)->post("/people/{$this->parent->id}/photo", [])->assertForbidden();
    $this->actingAs($this->other)->post("/people/{$this->child->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpeg())])->assertForbidden();
    $this->actingAs($this->other)->delete("/people/{$this->parent->id}/photo")->assertForbidden();
    expect($this->child->fresh()->photo_path)->toBeNull();
});

it('lets a manager of a child upload and remove its photo and audits it', function () {
    $manager = User::factory()->create();
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $manager->id]);

    $this->actingAs($manager)->post("/people/{$this->child->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpeg())])->assertStatus(303);

    expect($this->child->fresh()->photo_path)->not->toBeNull();
    $row = AuditEntry::query()->where('event', 'profile_changed_by_other')->sole();
    expect($row->actor_id)->toBe($manager->id)->and($row->target_user_id)->toBe($this->child->id)->and($row->meta)->toBe(['field' => 'photo']);

    $this->actingAs($manager)->delete("/people/{$this->child->id}/photo")->assertStatus(303);
    expect($this->child->fresh()->photo_path)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'profile_changed_by_other')->count())->toBe(2);
});

it('lets an admin change a child photo', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post("/people/{$this->child->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpeg())])->assertStatus(303);
    expect($this->child->fresh()->photo_version)->toBe(1);
});

it('rejects a text file named .jpg with err_type', function () {
    $this->actingAs($this->parent)
        ->post("/people/{$this->parent->id}/photo", ['photo' => jpegUpload(ImageFixtures::html(), 'x.jpg')])
        ->assertSessionHasErrors(['photo' => 'profile.photo.err_type']);
    expect($this->parent->fresh()->photo_path)->toBeNull();
});

it('rejects a file over 5 MB with err_size', function () {
    $this->actingAs($this->parent)
        ->post("/people/{$this->parent->id}/photo", ['photo' => UploadedFile::fake()->create('big.jpg', 5 * 1024 + 10, 'image/jpeg')])
        ->assertSessionHasErrors(['photo' => 'profile.photo.err_size']);
});

it('rejects a huge pixel count with err_pixels', function () {
    $this->actingAs($this->parent)
        ->post("/people/{$this->parent->id}/photo", ['photo' => jpegUpload(ImageFixtures::pngBomb(), 'bomb.png')])
        ->assertSessionHasErrors(['photo' => 'profile.photo.err_pixels']);
});

it('removes the own photo', function () {
    $this->actingAs($this->parent)->post("/people/{$this->parent->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpeg())]);
    $paths = [$this->parent->fresh()->photo_path, $this->parent->fresh()->photo_thumb_path];

    $this->actingAs($this->parent)->delete("/people/{$this->parent->id}/photo")->assertStatus(303);

    expect($this->parent->fresh()->photo_path)->toBeNull();
    Storage::disk('local')->assertMissing($paths);
});

function withPhoto(User $user): void
{
    test()->actingAs($user)->post("/people/{$user->id}/photo", ['photo' => jpegUpload(ImageFixtures::jpeg())])->assertStatus(303);
    auth()->forgetGuards();
}

it('serves a photo privately with ETag and 304 to an allowed viewer', function () {
    withPhoto($this->parent);
    $viewer = User::factory()->withGrant(Module::Todo, AccessLevel::View)->create();

    $response = $this->actingAs($viewer)->get("/people/{$this->parent->id}/photo/thumb")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('no-store')
        ->and($response->headers->get('Vary'))->toContain('Cookie')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $this->actingAs($viewer)->get("/people/{$this->parent->id}/photo/thumb", ['If-None-Match' => $etag])->assertStatus(304);
});

it('answers every refusal, unknown id and missing photo with the same no-store 404', function () {
    withPhoto($this->parent);
    $nobody = User::factory()->create();
    $viewer = User::factory()->withGrant(Module::Todo, AccessLevel::View)->create();

    $cases = [
        [$nobody, "/people/{$this->parent->id}/photo/thumb"],
        [$viewer, '/people/999999/photo/thumb'],
        [$viewer, "/people/{$this->other->id}/photo/thumb"],
    ];
    $bodies = [];
    foreach ($cases as [$user, $uri]) {
        $r = $this->actingAs($user)->get($uri)->assertNotFound();
        expect((string) $r->headers->get('Cache-Control'))->toContain('no-store')
            ->and($r->headers->get('Content-Type'))->not->toBe('image/jpeg');
        $bodies[] = $r->getContent();
        auth()->forgetGuards();
    }
    expect(count(array_unique($bodies)))->toBe(1);
    $this->get("/people/{$this->parent->id}/photo/thumb")->assertRedirect('/login');
});

it('rejects an unknown variant', function () {
    withPhoto($this->parent);
    $this->actingAs($this->parent)->get("/people/{$this->parent->id}/photo/huge")->assertNotFound();
});
