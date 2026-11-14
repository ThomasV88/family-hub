<?php

use App\Access\AccessLevel;
use App\Models\MedicalSubject;
use App\Models\User;
use Tests\Support\MedicalFixtures;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

it('passes with medical subjects and memberships of synthetic users only', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $linked = User::factory()->create(['email' => 'a.2@example.test']);
    MedicalFixtures::subject(['created_by' => $synthetic->id, 'user_id' => $linked->id], [[$synthetic, AccessLevel::Edit]]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails on a medical subject created by, linked to or shared with a real user', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    MedicalFixtures::subject(['created_by' => $real->id], [[$real, AccessLevel::View]]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic medical_subjects')
        ->expectsOutputToContain('non-synthetic medical_subject_members')
        ->assertExitCode(1);
});

it('fails on a subject linked to a real user even when a synthetic user created it', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    MedicalSubject::factory()->create(['created_by' => $synthetic->id, 'user_id' => $real->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_subjects')->assertExitCode(1);
});

it('fails on a medical subject whose creator was deleted (null created_by)', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    MedicalSubject::factory()->create(['created_by' => null]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_subjects')->assertExitCode(1);
});
