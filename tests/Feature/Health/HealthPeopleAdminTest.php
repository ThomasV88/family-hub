<?php

use App\Access\AccessLevel;
use App\Models\AuditEntry;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

function freshAdmin(): User
{
    return User::factory()->admin()->create();
}

/** @return list<string> */
function changes(): array
{
    return AuditEntry::where('event', 'medical_access_changed')->orderBy('id')->get()->map(fn ($r) => $r->meta['change'])->all();
}

function membersUrl(MedicalSubject $s): string
{
    return "/settings/health-people/{$s->id}/members";
}

function linkUrl(MedicalSubject $s): string
{
    return "/settings/health-people/{$s->id}/link";
}

it('grants, changes and revokes member levels with one content-free audit row each', function () {
    $admin = freshAdmin();
    $parent = User::factory()->create();
    $child = User::factory()->create();
    MedicalFixtures::grantHealth($parent);
    MedicalFixtures::grantHealth($child);
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [$parent->id => 'edit', $child->id => 'view']])->assertRedirect();
    expect(DB::table('medical_subject_members')->where('subject_id', $s->id)->pluck('level', 'user_id')->all())
        ->toEqual([$parent->id => 2, $child->id => 1]);
    $rows = AuditEntry::where('event', 'medical_access_changed')->get();
    expect($rows)->toHaveCount(2)->and($rows->every(fn ($r) => $r->meta['change'] === 'granted'))->toBeTrue();
    expect($rows->map(fn ($r) => $r->meta['change'])->unique()->values()->all())->toBe(['granted']);

    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [$parent->id => 'view', $child->id => 'none']])->assertRedirect();
    expect(DB::table('medical_subject_members')->where('subject_id', $s->id)->pluck('level', 'user_id')->all())->toEqual([$parent->id => 1]);
    expect(collect(changes())->sort()->values()->all())
        ->toBe(['granted', 'granted', 'level', 'revoked']);
});

it('records an admin adding themselves as a distinct event', function () {
    $admin = freshAdmin();
    MedicalFixtures::grantHealth($admin);
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [$admin->id => 'view']])->assertRedirect();

    expect(AuditEntry::where('event', 'medical_admin_self_added')->count())->toBe(1)
        ->and(AuditEntry::where('event', 'medical_access_changed')->count())->toBe(0);
});

it('refuses a level for a member without the Health module and malformed payloads, changing nothing', function () {
    $admin = freshAdmin();
    $plain = User::factory()->create();
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [$plain->id => 'view']])->assertSessionHasErrors(['levels' => 'health.access.needs_module']);
    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [999999 => 'view']])->assertSessionHasErrors('levels');
    $this->actingAs($admin)->put(membersUrl($s), ['levels' => ['x' => 'view']])->assertSessionHasErrors('levels');
    $this->actingAs($admin)->put(membersUrl($s), ['levels' => [$plain->id => 'owner']])->assertSessionHasErrors();
    $this->actingAs($admin)->put(membersUrl($s), [])->assertSessionHasErrors('levels');

    expect(DB::table('medical_subject_members')->count())->toBe(0)->and(AuditEntry::count())->toBe(0);
});

it('links, unlinks and switches own-record access, with one neutral refusal shape', function () {
    $admin = freshAdmin();
    $m = User::factory()->create();
    MedicalFixtures::grantHealth($m);
    $other = User::factory()->create();
    $a = MedicalSubject::factory()->create();
    $b = MedicalSubject::factory()->linkedTo($other)->create();

    $this->actingAs($admin)->put(linkUrl($a), ['user_id' => $m->id, 'self_access_enabled' => true])->assertRedirect();
    expect($a->fresh()->user_id)->toBe($m->id);

    $this->actingAs($admin)->put(linkUrl($a), ['user_id' => $m->id, 'self_access_enabled' => false])->assertRedirect();
    expect($a->fresh()->self_access_enabled)->toBeFalse()
        ->and(changes())->toBe(['linked', 'self_access_off']);

    // Already linked elsewhere, unknown id and a non-integer give the identical error.
    $errors = [];
    foreach ([$other->id, 999999, 'abc'] as $id) {
        $res = $this->actingAs($admin)->put(linkUrl($a), ['user_id' => $id, 'self_access_enabled' => true]);
        $res->assertSessionHasErrors(['user_id' => 'health.access.link_failed']);
        $errors[] = session('errors')->getBag('default')->toArray();
    }
    expect(array_unique(array_map('json_encode', $errors)))->toHaveCount(1);
    expect($a->fresh()->user_id)->toBe($m->id)->and($b->fresh()->user_id)->toBe($other->id);

    $this->actingAs($admin)->put(linkUrl($a), ['user_id' => null, 'self_access_enabled' => true])->assertRedirect();
    expect($a->fresh()->user_id)->toBeNull();
    expect(AuditEntry::where('event', 'medical_access_changed')->get()->every(fn ($r) => collect(array_keys($r->meta))->sort()->values()->all() === ['change', 'subject']))->toBeTrue();
});

it('records an admin linking themselves as the self-added event, not a plain link', function () {
    $admin = freshAdmin();
    MedicalFixtures::grantHealth($admin);
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put(linkUrl($s), ['user_id' => $admin->id, 'self_access_enabled' => true])->assertRedirect();

    expect($s->fresh()->user_id)->toBe($admin->id);
    $row = AuditEntry::where('event', 'medical_admin_self_added')->sole();
    expect($row->meta)->toBe(['subject' => $s->id])
        ->and(AuditEntry::where('event', 'medical_access_changed')->count())->toBe(0);
});

it('refuses to link an account without the Health module with the same neutral error', function () {
    $admin = freshAdmin();
    $plain = User::factory()->create();
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put(linkUrl($s), ['user_id' => $plain->id, 'self_access_enabled' => true])
        ->assertSessionHasErrors(['user_id' => 'health.access.link_failed']);

    expect($s->fresh()->user_id)->toBeNull()->and(AuditEntry::count())->toBe(0);
});

it('answers 404 for an unknown person and 403 for a non-admin', function () {
    $admin = freshAdmin();
    $member = User::factory()->create();
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->put('/settings/health-people/not-a-uuid/members', ['levels' => []])->assertNotFound();
    $this->actingAs($admin)->put('/settings/health-people/'.Str::uuid().'/link', ['user_id' => null, 'self_access_enabled' => true])->assertNotFound();
    $this->actingAs($member)->put(membersUrl($s), ['levels' => []])->assertForbidden();
    $this->actingAs($member)->put(linkUrl($s), ['user_id' => $member->id, 'self_access_enabled' => true])->assertForbidden();
    expect($s->fresh()->user_id)->toBeNull();
});

it('gives an admin with the Health module no record access to a person they were not granted', function () {
    $admin = freshAdmin();
    MedicalFixtures::grantHealth($admin);
    $s = MedicalSubject::factory()->create();

    $this->actingAs($admin)->get('/settings/members')->assertOk();
    $this->actingAs($admin)->get("/health/people/{$s->id}")->assertForbidden();
});

it('puts names only on the admin Members page prop', function () {
    $admin = freshAdmin();
    $parent = User::factory()->create();
    MedicalFixtures::grantHealth($parent);
    MedicalFixtures::subject(['name' => 'Anna'], [[$parent, AccessLevel::Edit]]);

    $this->actingAs($admin)->get('/settings/members')->assertOk()->assertInertia(function (Assert $page) use ($parent) {
        $page->has('healthPeople', 1)->where('healthPeople.0.name', 'Anna')->where("healthPeople.0.members.{$parent->id}", 'edit');
        $keys = array_keys($page->toArray()['props']['healthPeople'][0]);
        expect($keys)->toBe(['id', 'name', 'hasAccount', 'linkedUserId', 'selfAccessEnabled', 'members']);
    });

    $this->actingAs($parent)->get('/settings/members')->assertForbidden();
});
