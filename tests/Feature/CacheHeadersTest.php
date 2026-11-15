<?php

use App\Access\AccessLevel;
use App\Access\Preset;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

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

it('keeps private, no-cache on health image previews (200 and 304) and no-store on their refusals, PDFs and downloads', function () {
    Storage::fake('medical');
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);
    $image = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($image->thumb_path, ImageFixtures::jpeg());
    Storage::disk('medical')->put($image->path, ImageFixtures::jpeg());
    $pdf = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($pdf->path, MedicalFileFixtures::pdf());
    $url = "/health/people/{$person->id}/documents/{$image->id}/preview/thumb";

    $ok = $this->actingAs($user)->get($url)->assertOk();
    $cc = (string) $ok->headers->get('Cache-Control');
    $notModified = $this->actingAs($user)->get($url, ['If-None-Match' => $ok->headers->get('ETag')]);
    expect($cc)->toContain('private')->toContain('no-cache')->not->toContain('no-store')
        ->and($notModified->getStatusCode())->toBe(304)
        ->and((string) $notModified->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('no-store');

    $refused = $this->actingAs($user)->get("/health/people/{$person->id}/documents/{$pdf->id}/preview/thumb");
    $file = $this->actingAs($user)->get("/health/people/{$person->id}/documents/{$pdf->id}/file")->assertOk();
    $imageFile = $this->actingAs($user)->get("/health/people/{$person->id}/documents/{$image->id}/file")->assertOk();
    expect($refused->getStatusCode())->toBe(403)
        ->and((string) $refused->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $file->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and((string) $imageFile->headers->get('Cache-Control'))->toContain('no-store');
});
