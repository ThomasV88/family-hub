<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\AiConsent;
use App\Medical\Queries\SystemQuery;
use App\Models\AuditEntry;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject} */
function consentEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function consentUrl(string $id): string
{
    return "/health/people/{$id}/ai-consent";
}

describe('toggle', function () {
    it('is off by default and turns on and off with an audit row each', function () {
        [$user, $person] = consentEditor();
        expect(AiConsent::allows($person))->toBeFalse();

        $this->actingAs($user)->patch(consentUrl($person->id), ['value' => true])
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.ai.on_toast');
        $row = MedicalSubject::find($person->id);
        expect($row->ai_consent)->toBeTrue()->and($row->ai_consent_changed_at)->not->toBeNull()->and($row->ai_consent_changed_by)->toBe($user->id);
        expect(AiConsent::allows($person->id))->toBeTrue();
        $audit = AuditEntry::where('event', 'medical_consent_changed')->get();
        expect($audit)->toHaveCount(1)->and($audit[0]->meta)->toEqual(['subject' => $person->id, 'value' => 'on']);

        $this->actingAs($user)->patch(consentUrl($person->id), ['value' => false])->assertSessionHas('toast.key', 'health.ai.off_toast');
        expect(AiConsent::allows($person->id))->toBeFalse();
        expect(AuditEntry::where('event', 'medical_consent_changed')->orderBy('id')->get()->last()->meta)->toEqual(['subject' => $person->id, 'value' => 'off']);
    });

    it('writes no audit row when the value is unchanged', function () {
        [$user, $person] = consentEditor();
        $this->actingAs($user)->patch(consentUrl($person->id), ['value' => false])->assertRedirect();
        expect(AuditEntry::where('event', 'medical_consent_changed')->count())->toBe(0);
    });

    it('leaves the flag unchanged when the audit write fails', function () {
        [$user, $person] = consentEditor();
        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($user)->patch(consentUrl($person->id), ['value' => true]))->toThrow(RuntimeException::class);
        expect(MedicalSubject::find($person->id)->ai_consent)->toBeFalse();
    });

    it('refuses an admin with Health access but no membership', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        MedicalFixtures::grantHealth($admin);
        $person = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);

        $this->actingAs($admin)->patch(consentUrl($person->id), ['value' => true])->assertForbidden();
        expect(MedicalSubject::find($person->id)->ai_consent)->toBeFalse();
    });
});

describe('identical 403', function () {
    it('answers view, hidden, own-off, unknown and malformed targets the same, with a valid or an invalid body', function () {
        [$user] = consentEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $random = (string) Str::uuid();

        $baseline = $this->actingAs($user)->patch(consentUrl($random), ['value' => true]);
        $baseline->assertForbidden();
        $reference = $baseline->viewData('page');
        foreach ([$viewOnly->id, $hidden->id, $ownOff->id, $random, 'abc'] as $id) {
            foreach ([['value' => true], [], ['value' => 'maybe']] as $body) {
                $response = $this->actingAs($user)->patch(consentUrl($id), $body);
                $response->assertForbidden()->assertSessionHasNoErrors();
                $page = $response->viewData('page');
                expect(['component' => $page['component'], 'props' => $page['props']])->toEqual(['component' => $reference['component'], 'props' => $reference['props']], $id);
            }
        }
        expect(MedicalSubject::where('ai_consent', true)->count())->toBe(0);
        expect(AuditEntry::where('event', 'medical_consent_changed')->count())->toBe(0);
    });

    it('validates the body only for an editor of the person', function () {
        [$user, $person] = consentEditor();
        $this->actingAs($user)->patch(consentUrl($person->id), ['value' => 'maybe'])->assertSessionHasErrors('value');
    });
});

describe('AiConsent::allows', function () {
    it('is false for a missing subject, a malformed id and any error', function () {
        expect(AiConsent::allows((string) Str::uuid()))->toBeFalse()->and(AiConsent::allows('abc'))->toBeFalse();

        $person = MedicalSubject::factory()->create(['ai_consent' => true]);
        expect(AiConsent::allows($person))->toBeTrue();
        $this->mock(SystemQuery::class)->shouldReceive('aiConsentFor')->andThrow(new RuntimeException('db down'));
        expect(AiConsent::allows($person))->toBeFalse();
    });

    it('ignores the in-memory attribute of a passed model', function () {
        $person = MedicalSubject::factory()->create();
        $person->ai_consent = true;
        expect(AiConsent::allows($person))->toBeFalse();
    });
});

describe('SystemQuery::aiConsentFor', function () {
    it('answers a boolean for a uuid and runs no SQL for a non-uuid', function () {
        $person = MedicalSubject::factory()->create(['ai_consent' => true]);
        $q = new SystemQuery;
        expect($q->aiConsentFor($person->id))->toBeTrue()->and($q->aiConsentFor((string) Str::uuid()))->toBeFalse();

        DB::enableQueryLog();
        expect($q->aiConsentFor('abc'))->toBeFalse();
        expect(DB::getQueryLog())->toBe([]);
    });
});
