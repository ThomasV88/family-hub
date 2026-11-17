<?php

use App\Access\AccessLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('medical');
});

/** @return array<string, mixed> the Inertia page object of a GET */
function fhPhotoPage($response): array
{
    preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $response->getContent(), $m);
    expect($m)->not->toBeEmpty();

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}

it('exposes the linked user id as photoUserId of a person the viewer may open', function () {
    $viewer = User::factory()->create();
    MedicalFixtures::grantHealth($viewer);
    $linked = User::factory()->create();
    $person = MedicalFixtures::subject(['name' => 'Linked', 'user_id' => $linked->id], [[$viewer, AccessLevel::View]]);

    $page = fhPhotoPage($this->actingAs($viewer)->get("/health/people/{$person->id}"));

    expect($page['props']['person']['photoUserId'])->toBe($linked->id);
});

it('gives null photoUserId to a person without an account', function () {
    $viewer = User::factory()->create();
    MedicalFixtures::grantHealth($viewer);
    $person = MedicalFixtures::subject(['name' => 'No account'], [[$viewer, AccessLevel::View]]);

    $page = fhPhotoPage($this->actingAs($viewer)->get("/health/people/{$person->id}"));

    expect($page['props']['person']['photoUserId'])->toBeNull();
});

it('never puts a photo id in the people list or for a person the viewer may not open', function () {
    $viewer = User::factory()->create();
    MedicalFixtures::grantHealth($viewer);
    $other = User::factory()->create();
    $hidden = MedicalFixtures::subject(['name' => 'Hidden', 'user_id' => $other->id], [[$other, AccessLevel::Edit]]);

    $response = $this->actingAs($viewer)->get("/health/people/{$hidden->id}");
    $response->assertForbidden();
    expect((string) $response->getContent())->not->toContain('photoUserId');

    $own = MedicalFixtures::subject(['name' => 'Mine'], [[$viewer, AccessLevel::View]]);
    $list = fhPhotoPage($this->actingAs($viewer)->get("/health/people/{$own->id}"))['props']['people'];
    foreach ($list as $p) {
        expect($p)->not->toHaveKey('photoUserId')->and($p)->not->toHaveKey('user_id');
    }
});
