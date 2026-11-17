<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    // With debug on, a JSON refusal carries the throwing file and line; bodies are compared as production renders them.
    config(['app.debug' => false]);
});

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument} an editor, their person and a document with three pages of text */
function docTextViewScene(AccessLevel $level = AccessLevel::Edit): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, $level);
    $subject = MedicalFixtures::subject(['name' => 'Text Person'], [[$user, $level]]);
    $doc = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Discharge letter']);
    DocumentTextFixtures::save($doc, ['First page text', 'Second page text', 'Third '.DocumentTextFixtures::CANARY], 3, true, 'sk');

    return [$user, $subject, $doc];
}

function docTextViewUrl(string $person, string $document): string
{
    return "/health/people/{$person}/documents/{$document}/text";
}

describe('show', function () {
    it('returns the pages of a visible document with the page numbers from one', function () {
        [$user, $subject, $doc] = docTextViewScene();

        $response = $this->actingAs($user)->getJson(docTextViewUrl($subject->id, $doc->id))->assertOk();

        expect($response->json())->toBe([
            'pages' => [['page' => 1, 'text' => 'First page text'], ['page' => 2, 'text' => 'Second page text'], ['page' => 3, 'text' => 'Third '.DocumentTextFixtures::CANARY]],
            'pagesRead' => 3, 'pagesTotal' => 3, 'complete' => true, 'language' => 'sk',
        ])->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    });

    it('lets a view-only member read it', function () {
        [$user, $subject, $doc] = docTextViewScene(AccessLevel::View);

        $this->actingAs($user)->getJson(docTextViewUrl($subject->id, $doc->id))->assertOk()->assertJsonPath('pages.0.text', 'First page text');
    });

    it('reports a partial text honestly', function () {
        [$user, $subject] = docTextViewScene();
        $doc = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Partial']);
        DocumentTextFixtures::save($doc, ['only one'], 5, false, null);

        $this->actingAs($user)->getJson(docTextViewUrl($subject->id, $doc->id))->assertOk()
            ->assertJsonPath('pagesRead', 1)->assertJsonPath('pagesTotal', 5)->assertJsonPath('complete', false)->assertJsonPath('language', null);
    });

    it('answers a visible document without text with 404', function () {
        [$user, $subject] = docTextViewScene();
        $bare = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Bare']);

        $response = $this->actingAs($user)->getJson(docTextViewUrl($subject->id, $bare->id))->assertNotFound();

        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('answers a hidden person, an archived document, a random id, a malformed id and another family document with the identical 403', function () {
        [$user, $subject, $doc] = docTextViewScene();
        $other = User::factory()->create();
        MedicalFixtures::grantHealth($other, AccessLevel::Edit);
        $foreign = MedicalFixtures::subject(['name' => 'Foreign'], [[$other, AccessLevel::Edit]]);
        $foreignDoc = MedicalDocument::factory()->forSubject($foreign)->create(['title' => MedicalPrivacy::CANARY]);
        DocumentTextFixtures::save($foreignDoc, [MedicalPrivacy::CANARY.' secret']);
        $archived = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Archived']);
        DocumentTextFixtures::save($archived, ['archived words']);
        DB::table('medical_documents')->where('id', $archived->id)->update(['archived_at' => now()]);

        $bodies = [];
        foreach ([
            'hidden person' => docTextViewUrl($foreign->id, $foreignDoc->id),
            'archived document' => docTextViewUrl($subject->id, $archived->id),
            'random document' => docTextViewUrl($subject->id, (string) Str::uuid7()),
            'random person' => docTextViewUrl((string) Str::uuid7(), $doc->id),
            'malformed' => docTextViewUrl('abc', 'abc'),
            'foreign document in own person' => docTextViewUrl($subject->id, $foreignDoc->id),
        ] as $label => $url) {
            app('auth')->forgetGuards();
            $response = $this->actingAs($user)->getJson($url);
            expect($response->getStatusCode())->toBe(403, $label);
            $bodies[$label] = MedicalPrivacy::normalise($response);
        }
        expect(array_unique($bodies))->toHaveCount(1);
        expect(implode('', $bodies))->not->toContain(MedicalPrivacy::CANARY);
    });

    it('is refused for a member without Health access', function () {
        [, $subject, $doc] = docTextViewScene();
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->getJson(docTextViewUrl($subject->id, $doc->id))->assertForbidden();
    });
});

describe('destroy', function () {
    it('removes the text for an editor with 204 and one content-free audit row', function () {
        [$user, $subject, $doc] = docTextViewScene();
        $before = (int) (DB::table('audit_logs')->max('id') ?? 0);

        $response = $this->actingAs($user)->deleteJson(docTextViewUrl($subject->id, $doc->id));

        expect($response->getStatusCode())->toBe(204)->and(DocumentTextFixtures::row($doc))->toBeNull();
        $rows = AuditEntry::where('event', AuditEvent::MedicalTextRemoved->value)->where('id', '>', $before)->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->meta)->toEqual(['subject' => $subject->id, 'record' => $doc->id])
            ->and($rows[0]->module)->toBe('health')
            ->and($rows[0]->actor_id)->toBe($user->id);
        $everything = json_encode(DB::table('audit_logs')->where('id', '>', $before)->get()->all());
        expect($everything)->not->toContain('Discharge letter')->not->toContain(DocumentTextFixtures::CANARY)->not->toContain('First page');
        // The document and its other data stay.
        expect(MedicalDocument::query()->whereKey($doc->id)->exists())->toBeTrue();
    });

    it('refuses a view-only member and keeps the row', function () {
        [$user, $subject, $doc] = docTextViewScene(AccessLevel::View);

        $this->actingAs($user)->deleteJson(docTextViewUrl($subject->id, $doc->id))->assertForbidden();

        expect(DocumentTextFixtures::row($doc))->not->toBeNull()
            ->and(AuditEntry::where('event', AuditEvent::MedicalTextRemoved->value)->count())->toBe(0);
    });

    it('answers hidden, archived, random and malformed targets with the same 403 and removes nothing', function () {
        [$user, $subject, $doc] = docTextViewScene();
        $other = User::factory()->create();
        MedicalFixtures::grantHealth($other, AccessLevel::Edit);
        $foreign = MedicalFixtures::subject(['name' => 'Foreign'], [[$other, AccessLevel::Edit]]);
        $foreignDoc = MedicalDocument::factory()->forSubject($foreign)->create();
        DocumentTextFixtures::save($foreignDoc, ['foreign words']);
        $archived = MedicalDocument::factory()->forSubject($subject)->create();
        DocumentTextFixtures::save($archived, ['archived words']);
        DB::table('medical_documents')->where('id', $archived->id)->update(['archived_at' => now()]);
        $bare = MedicalDocument::factory()->forSubject($subject)->create();

        $bodies = [];
        foreach ([
            'hidden' => docTextViewUrl($foreign->id, $foreignDoc->id),
            'archived' => docTextViewUrl($subject->id, $archived->id),
            'random' => docTextViewUrl($subject->id, (string) Str::uuid7()),
            'no text' => docTextViewUrl($subject->id, $bare->id),
            'malformed' => docTextViewUrl('abc', 'abc'),
            'mismatched pair' => docTextViewUrl($subject->id, $foreignDoc->id),
        ] as $label => $url) {
            app('auth')->forgetGuards();
            $response = $this->actingAs($user)->deleteJson($url);
            expect($response->getStatusCode())->toBe(403, $label);
            $bodies[$label] = MedicalPrivacy::normalise($response);
        }
        expect(array_unique($bodies))->toHaveCount(1)
            ->and(DocumentTextFixtures::row($foreignDoc))->not->toBeNull()
            ->and(DocumentTextFixtures::row($archived))->not->toBeNull()
            ->and(DocumentTextFixtures::row($doc))->not->toBeNull()
            ->and(AuditEntry::where('event', AuditEvent::MedicalTextRemoved->value)->count())->toBe(0);
    });

    it('answers a second removal with 403 and writes no second audit row', function () {
        [$user, $subject, $doc] = docTextViewScene();
        $url = docTextViewUrl($subject->id, $doc->id);

        expect($this->actingAs($user)->deleteJson($url)->getStatusCode())->toBe(204);
        app('auth')->forgetGuards();
        expect($this->actingAs($user)->deleteJson($url)->getStatusCode())->toBe(403)
            ->and(AuditEntry::where('event', AuditEvent::MedicalTextRemoved->value)->count())->toBe(1);
    });

    it('writes no audit row when the audit write fails and removes nothing', function () {
        [$user, $subject, $doc] = docTextViewScene();
        // Only the removal's own audit write fails (the access middleware's read/write row must still go through).
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void
            {
                if ($event === AuditEvent::MedicalTextRemoved) {
                    throw new RuntimeException('audit write failed');
                }
                parent::record($event, $actor, $meta, $target, $module);
            }
        });

        try {
            $this->withoutExceptionHandling()->actingAs($user)->deleteJson(docTextViewUrl($subject->id, $doc->id));
        } catch (Throwable) {
            // expected: the request fails closed
        }

        expect(DocumentTextFixtures::row($doc))->not->toBeNull();
    });
});

it('labels the audit event in every locale and lists it in the health group with ids only', function (string $locale) {
    $event = AuditEvent::MedicalTextRemoved;
    $detail = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true)['audit']['detail'];

    expect($event->group())->toBe('health')->and($event->metaKeys())->toBe(['subject', 'record'])
        ->and(AuditEvent::eventsForGroup('health')['events'])->toContain('medical_text_removed')
        ->and(trim($detail['medical_text_removed'] ?? ''))->not->toBe('');
})->with(['en', 'sk', 'pl']);
