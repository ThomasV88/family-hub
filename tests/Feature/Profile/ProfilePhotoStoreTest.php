<?php

use App\Models\User;
use App\Models\Wish;
use App\Profile\ProfilePhotoStore;
use App\Profile\ProfilePhotoWriteFailed;
use App\Wishlist\Catalog\ProcessedImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->store = app(ProfilePhotoStore::class);
    $this->image = new ProcessedImage('full-bytes', 'thumb-bytes', 10, 10);
});

it('stores two files under the persisted profile-photos prefix and bumps the version', function () {
    $user = User::factory()->create();

    $this->store->put($user, $this->image);

    $user->refresh();
    expect($user->photo_version)->toBe(1)
        ->and($user->photo_path)->toStartWith("wish-images/profile-photos/{$user->id}/")->toEndWith('-full.jpg')
        ->and($user->photo_thumb_path)->toStartWith("wish-images/profile-photos/{$user->id}/")->toEndWith('-thumb.jpg');
    Storage::disk('local')->assertExists([$user->photo_path, $user->photo_thumb_path]);
});

it('replaces the old pair and bumps the version again', function () {
    $user = User::factory()->create();
    $this->store->put($user, $this->image);
    $first = [$user->photo_path, $user->photo_thumb_path];

    $this->store->put($user, $this->image);

    $user->refresh();
    expect($user->photo_version)->toBe(2);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists([$user->photo_path, $user->photo_thumb_path]);
});

it('keeps the row and the old files when a write fails and leaves no new file behind', function () {
    $user = User::factory()->create();
    $this->store->put($user, $this->image);
    $old = [$user->photo_path, $user->photo_thumb_path];

    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('put')->andReturn(false);
    Storage::set('local', $failing);

    expect(fn () => $this->store->put($user, $this->image))->toThrow(ProfilePhotoWriteFailed::class);

    $fresh = $user->fresh();
    expect($fresh->photo_version)->toBe(1)->and([$fresh->photo_path, $fresh->photo_thumb_path])->toBe($old);
    expect($real->allFiles("wish-images/profile-photos/{$user->id}"))->toHaveCount(2);
});

it('removes the files, clears the paths and bumps the version', function () {
    $user = User::factory()->create();
    $this->store->put($user, $this->image);
    $old = [$user->photo_path, $user->photo_thumb_path];

    $this->store->remove($user);

    $user->refresh();
    expect($user->photo_path)->toBeNull()->and($user->photo_thumb_path)->toBeNull()->and($user->photo_version)->toBe(2);
    Storage::disk('local')->assertMissing($old);
});

it('wishlist:prune keeps a referenced profile photo, deletes an old unreferenced one and still deletes a wish orphan', function () {
    $disk = Storage::disk('local');
    $user = User::factory()->create();
    $this->store->put($user, $this->image);
    $disk->put('wish-images/profile-photos/'.$user->id.'/orphan-old.jpg', 'x');
    $disk->put('wish-images/profile-photos/'.$user->id.'/orphan-new.jpg', 'x');
    $disk->put('wish-images/1/wish-orphan.jpg', 'x');
    foreach ([$user->photo_path, $user->photo_thumb_path, 'wish-images/profile-photos/'.$user->id.'/orphan-old.jpg', 'wish-images/1/wish-orphan.jpg'] as $path) {
        touch($disk->path($path), time() - 7200);
    }
    Wish::factory()->create();

    $this->artisan('wishlist:prune')->assertSuccessful();

    $disk->assertExists([$user->photo_path, $user->photo_thumb_path, 'wish-images/profile-photos/'.$user->id.'/orphan-new.jpg']);
    $disk->assertMissing(['wish-images/profile-photos/'.$user->id.'/orphan-old.jpg', 'wish-images/1/wish-orphan.jpg']);
});
