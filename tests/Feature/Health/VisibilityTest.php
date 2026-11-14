<?php

use App\Access\AccessLevel;
use App\Access\Preset;
use App\Audit\AuditLogger;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\User;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

function mdUser(AccessLevel $health = AccessLevel::Edit, bool $admin = false): User
{
    $user = $admin
        ? User::factory()->admin()->create()
        : User::factory()->withPreset(Preset::Parent)->create();
    MedicalFixtures::grantHealth($user, $health);

    return $user;
}

it('returns member subjects and the own linked profile while self access is on, never the rest', function () {
    $v = mdUser();
    $o = mdUser();
    $a = MedicalFixtures::subject(['name' => 'A'], [[$v, AccessLevel::View], [$o, AccessLevel::Edit]]);
    $s = MedicalFixtures::subject(['name' => 'S', 'user_id' => $v->id, 'self_access_enabled' => true]);
    $b = MedicalFixtures::subject(['name' => 'B'], [[$o, AccessLevel::Edit]]);
    $w = mdUser();
    $c = MedicalFixtures::subject(['name' => 'C', 'user_id' => $w->id, 'self_access_enabled' => false]);

    $ids = fn (AccessLevel $min = AccessLevel::View) => Visibility::subjectIds(Viewer::for($v), $min)->pluck('subject_id')->all();
    $query = new SubjectQuery(app(AuditLogger::class));
    $visible = $query->visible(Viewer::for($v))->pluck('id')->all();

    expect($visible)->toContain($a->id, $s->id)->not->toContain($b->id)->not->toContain($c->id)
        ->and($visible)->toHaveCount(2);

    // Edit scope: the View member is out, the own linked profile counts as Edit.
    $edit = Visibility::apply(MedicalSubject::query(), Viewer::for($v), 'id', AccessLevel::Edit)->pluck('id')->all();
    expect($edit)->toBe([$s->id]);
    expect($ids())->not->toBeEmpty()
        // C is linked to W with self access off and W has no membership: W sees nothing at all.
        ->and($query->visible(Viewer::for($w))->all())->toBe([]);
});

it('caps a member Edit at the module level and gives an admin with a grant no bypass', function () {
    $viewOnly = mdUser(AccessLevel::View);
    $edit = mdUser();
    $admin = mdUser(AccessLevel::Edit, admin: true);
    $subject = MedicalFixtures::subject([], [[$viewOnly, AccessLevel::Edit], [$edit, AccessLevel::Edit]]);
    $query = new SubjectQuery(app(AuditLogger::class));

    expect($query->levelFor(Viewer::for($viewOnly), $subject))->toBe(AccessLevel::View)
        ->and($query->levelFor(Viewer::for($edit), $subject))->toBe(AccessLevel::Edit)
        ->and($query->visible(Viewer::for($admin))->all())->toBe([])
        ->and($query->findVisible(Viewer::for($admin), (string) $subject->id))->toBeNull()
        ->and($query->findVisible(Viewer::for($viewOnly), (string) $subject->id, AccessLevel::Edit))->toBeNull();
});

it('puts the member predicate and the own branch in the generated SQL', function () {
    $sql = Visibility::subjectIds(Viewer::for(mdUser()))->toSql();

    expect($sql)->toContain('"medical_subject_members"')
        ->and($sql)->toMatch('/"user_id" = \?/')
        ->and($sql)->toContain('union')
        ->and($sql)->toContain('"self_access_enabled" = ?')
        ->and(MedicalPrivacy::hasMembershipPredicate($sql))->toBeTrue();
});
