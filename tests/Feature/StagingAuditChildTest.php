<?php

use App\Models\User;
use App\Models\WishlistManager;
use Database\Seeders\StagingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

// A synthetic child carries the demo suffix and is managed only by synthetic (example.test) members.
function demoChild(string $name = 'Dieťa (demo)', ?User $manager = null): User
{
    $child = User::factory()->child()->create(['name' => $name]);
    $manager ??= User::factory()->create(['email' => 'demo.parent.'.$child->id.'@example.test']);
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => $manager->id]);

    return $child;
}

it('fails on a real child (no e-mail, no demo suffix)', function () {
    User::factory()->child()->create(['name' => 'Real Kid']);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic users')->assertExitCode(1);
});

it('passes for a demo child', function () {
    demoChild();

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails on a real child photo and passes on a synthetic child photo', function () {
    $demo = demoChild();
    $demo->forceFill(['photo_path' => 'wish-images/profile-photos/x.jpg'])->save();
    $this->artisan('staging:audit')->assertExitCode(0);

    $real = User::factory()->child()->create(['name' => 'Real Kid']);
    $real->forceFill(['photo_path' => 'wish-images/profile-photos/y.jpg'])->save();
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic users')->assertExitCode(1);
});

it('treats audit rows of a real child as real data', function () {
    $child = User::factory()->child()->create(['name' => 'Real Kid']);
    DB::table('audit_logs')->insert([
        'actor_id' => $child->id, 'event' => 'x',
    ]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic audit_logs')->assertExitCode(1);
});

it('purges synthetic children and their photo folders on reseed but never a real child', function () {
    Storage::fake('local');
    Storage::fake('medical');
    $demo = demoChild();
    $real = User::factory()->child()->create(['name' => 'Real Kid']);
    Storage::disk('local')->put("wish-images/profile-photos/{$demo->id}/photo.jpg", 'x');
    Storage::disk('local')->put("wish-images/profile-photos/{$real->id}/photo.jpg", 'x');

    (new StagingSeeder)->run();

    expect(User::find($demo->id))->toBeNull()
        ->and(User::find($real->id))->not->toBeNull();
    Storage::disk('local')->assertMissing("wish-images/profile-photos/{$demo->id}/photo.jpg");
    Storage::disk('local')->assertExists("wish-images/profile-photos/{$real->id}/photo.jpg");
});

it('passes on the seeded data including its child and fails once a real child is added', function () {
    Storage::fake('local');
    Storage::fake('medical');
    (new StagingSeeder)->run();
    $this->artisan('staging:audit')->assertExitCode(0);

    User::factory()->child()->create(['name' => 'Real Kid']);
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic users')->assertExitCode(1);
});

it('does not trust the name suffix: a real child named like a demo child is flagged and never purged', function () {
    Storage::fake('local');
    Storage::fake('medical');
    $realParent = User::factory()->create(['email' => 'mama@gmail.example']);
    $lookalike = demoChild('Anna (demo)', $realParent);
    $unmanaged = User::factory()->child()->create(['name' => 'Bruno (demo)']);
    Storage::disk('local')->put("wish-images/profile-photos/{$lookalike->id}/photo.jpg", 'x');

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic users')->assertExitCode(1);

    (new StagingSeeder)->run();

    expect(User::find($lookalike->id))->not->toBeNull()->and(User::find($unmanaged->id))->not->toBeNull();
    Storage::disk('local')->assertExists("wish-images/profile-photos/{$lookalike->id}/photo.jpg");
});

it('treats a child with one synthetic and one real manager as real', function () {
    $child = demoChild('Clara (demo)');
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => User::factory()->create(['email' => 'real@gmail.example'])->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic users')->assertExitCode(1);
});
