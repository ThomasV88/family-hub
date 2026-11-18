<?php

use App\Access\AccessLevel;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\RunExtractionJob;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    // The page component arrives with the UI plan.
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function ubHttpPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null, 'visits' => [],
        'medications' => [['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

/** @return array{user: User, person: MedicalSubject, batch: string, items: list<string>, docs: list<string>} item 1 has a ready proposal, item 2 is pending */
function ubHttpScene(AccessLevel $level = AccessLevel::Edit, bool $consent = true): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => $consent], [[$user, $level]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex', 'acknowledged_at' => now()]);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id,
        'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $items = [];
    $docs = [];
    foreach ([1, 2] as $i) {
        $doc = MedicalDocument::factory()->image()->forSubject($person)->create(['upload_batch_id' => $batch]);
        Storage::disk('medical')->put($doc->path, 'bytes');
        Storage::disk('medical')->put($doc->thumb_path, 'thumb');
        $item = (string) Str::uuid();
        $row = ['id' => $item, 'batch_id' => $batch, 'document_id' => $doc->id, 'position' => $i, 'created_at' => now(), 'updated_at' => now()];
        if ($i === 1) {
            $ext = (string) Str::uuid();
            DB::table('medical_ai_extractions')->insert([
                'id' => $ext, 'subject_id' => $person->id, 'document_id' => $doc->id, 'status' => 'ready', 'agent' => 'codex',
                'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $user->id, 'upload_batch_id' => $batch, 'queued_at' => now(),
                'payload' => json_encode(ubHttpPayload()), 'entry_count' => 1, 'model' => 'default', 'finished_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row += ['state' => 'sent', 'extraction_id' => $ext];
        }
        DB::table('medical_upload_batch_items')->insert($row);
        $items[] = $item;
        $docs[] = (string) $doc->id;
    }

    return ['user' => $user, 'person' => $person, 'batch' => $batch, 'items' => $items, 'docs' => $docs];
}

function ubHttpBase(MedicalSubject|string $person): string
{
    return '/health/people/'.($person instanceof MedicalSubject ? $person->id : $person).'/upload-batches';
}

/** Status and message only: the debug body of a 403 carries a stack trace with test line numbers. */
function ubHttpShape(TestResponse $response): string
{
    return $response->getStatusCode().'|'.(string) $response->json('message');
}

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> method, path under the batch URL, body */
function ubHttpRoutes(): array
{
    return [
        ['get', '', []],
        ['get', '/status', []],
        ['put', '/current', ['itemId' => '__ITEM__']],
        ['put', '/items/__ITEM__/choices', ['choices' => (object) []]],
        ['put', '/items/__ITEM__/adjustments', ['brightness' => 100, 'contrast' => 100, 'bw' => false]],
        ['post', '/items/__ITEM__/rescan', ['acknowledged' => true, 'providers' => ['codex']]],
        ['post', '/items/__ITEM__/continue', []],
        ['delete', '/items/__ITEM__', []],
        ['delete', '', []],
    ];
}

function ubHttpCall($test, User $user, string $method, string $person, string $batch, string $item, string $suffix, array $body): TestResponse
{
    $url = ubHttpBase($person).'/'.$batch.str_replace('__ITEM__', $item, $suffix);
    $body = json_decode(str_replace('__ITEM__', $item, (string) json_encode($body)), true);

    return $test->actingAs($user)->json($method, $url, $body);
}

describe('access', function () {
    it('answers the same 403 on every route for every kind of miss', function () {
        $s = ubHttpScene();
        $other = ubHttpScene();
        $unknown = (string) Str::uuid();
        $baseline = null;

        foreach (ubHttpRoutes() as [$method, $suffix, $body]) {
            $misses = [
                'malformed person' => ['not-a-uuid', $s['batch'], $s['items'][0], $s['user']],
                'unknown person' => [$unknown, $s['batch'], $s['items'][0], $s['user']],
                'person without membership' => [(string) $other['person']->id, $other['batch'], $other['items'][0], $s['user']],
                'malformed batch' => [(string) $s['person']->id, 'nope', $s['items'][0], $s['user']],
                'unknown batch' => [(string) $s['person']->id, $unknown, $s['items'][0], $s['user']],
                'batch of another person' => [(string) $s['person']->id, $other['batch'], $s['items'][0], $s['user']],
            ];
            if (str_contains($suffix, '__ITEM__') || $suffix === '/current') {
                $misses['malformed item'] = [(string) $s['person']->id, $s['batch'], 'nope', $s['user']];
                $misses['unknown item'] = [(string) $s['person']->id, $s['batch'], $unknown, $s['user']];
                $misses['item of another batch'] = [(string) $s['person']->id, $s['batch'], $other['items'][0], $s['user']];
            }
            foreach ($misses as $label => [$person, $batch, $item, $user]) {
                $r = ubHttpCall($this, $user, $method, $person, $batch, $item, $suffix, $body);
                $r->assertForbidden();
                $baseline ??= ubHttpShape($r);
                expect(ubHttpShape($r))->toBe($baseline, "$method $suffix / $label");
            }
        }
    });

    it('answers the same 403 for a View member on every route', function () {
        $s = ubHttpScene(AccessLevel::View);
        $baseline = null;

        foreach (ubHttpRoutes() as [$method, $suffix, $body]) {
            $r = ubHttpCall($this, $s['user'], $method, (string) $s['person']->id, $s['batch'], $s['items'][0], $suffix, $body);
            $r->assertForbidden();
            $baseline ??= ubHttpShape($r);
            expect(ubHttpShape($r))->toBe($baseline);
        }
        $this->actingAs($s['user'])->postJson(ubHttpBase($s['person']), ['acknowledged' => true, 'providers' => ['codex']])->assertForbidden();
        expect(ubHttpShape($this->actingAs($s['user'])->postJson(ubHttpBase($s['person']), [])))->toBe($baseline);
    });

    it('throttles every named batch route and keeps the Edit access middleware', function () {
        $names = ['store', 'show', 'status', 'current', 'choices', 'adjustments', 'rescan', 'continue', 'items.destroy', 'destroy'];
        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName('health.batches.'.$name);
            expect($route)->not->toBeNull();
            $middleware = $route->gatherMiddleware();
            expect(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue($name)
                ->and($middleware)->toContain('access:module,health,edit');
        }
    });

    it('is never cached', function () {
        $s = ubHttpScene();
        $r = $this->actingAs($s['user'])->getJson(ubHttpBase($s['person']).'/'.$s['batch'].'/status')->assertOk();
        expect((string) $r->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('store', function () {
    it('creates, then reports the open batch', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::Edit);
        $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);

        $id = $this->actingAs($user)->postJson(ubHttpBase($person), ['acknowledged' => true, 'providers' => ['codex']])->assertCreated()->json('batchId');
        expect(Str::isUuid($id))->toBeTrue();

        $this->actingAs($user)->postJson(ubHttpBase($person), [])->assertOk()->assertJson(['batchId' => $id, 'existing' => true]);
        expect(DB::table('medical_upload_batches')->count())->toBe(1);
    });

    it('answers 409 consent_off, unavailable and notice_required with the notice details', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::Edit);
        $person = MedicalFixtures::subject(['ai_consent' => false], [[$user, AccessLevel::Edit]]);

        $this->actingAs($user)->postJson(ubHttpBase($person), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(409)->assertExactJson(['reason' => 'consent_off']);

        DB::table('medical_subjects')->where('id', $person->id)->update(['ai_consent' => true]);
        app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedOut);
        $this->actingAs($user)->postJson(ubHttpBase($person), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(409)->assertExactJson(['reason' => 'unavailable']);

        app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedIn);
        $this->actingAs($user)->postJson(ubHttpBase($person), [])->assertStatus(409)->assertExactJson(['reason' => 'notice_required', 'providers' => ['codex']]);

        config(['ai.ocr.enabled' => true]);
        $this->actingAs($user)->postJson(ubHttpBase($person), [])->assertStatus(409)->assertExactJson(['reason' => 'notice_required', 'providers' => ['codex'], 'textNotice' => true]);
        expect(DB::table('medical_upload_batches')->count())->toBe(0);
    });

    it('rejects a malformed provider list with 422', function () {
        $s = ubHttpScene();
        $this->actingAs($s['user'])->postJson(ubHttpBase($s['person']), ['providers' => ['evil']])->assertStatus(422);
    });
});

describe('show and status', function () {
    it('renders the batch page for an Edit member', function () {
        $s = ubHttpScene();
        $this->actingAs($s['user'])->get(ubHttpBase($s['person']).'/'.$s['batch'])->assertOk()
            ->assertInertia(fn ($page) => $page->component('Health/UploadBatch', false)->where('batch.id', $s['batch'])->has('items', 2));
    });

    it('returns the polling payload without recognised text', function () {
        $s = ubHttpScene();
        $r = $this->actingAs($s['user'])->getJson(ubHttpBase($s['person']).'/'.$s['batch'].'/status')->assertOk();
        $r->assertJsonPath('batch.id', $s['batch'])->assertJsonCount(2, 'items');
        expect($r->getContent())->not->toContain('Ibuprofen');
    });
});

describe('choices, adjustments, current', function () {
    it('stores choices and answers them back', function () {
        $s = ubHttpScene();
        $url = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/choices';

        $this->actingAs($s['user'])->putJson($url, ['choices' => ['medication-0' => ['decision' => 'approve', 'values' => ['dose' => '5 mg']]]])
            ->assertOk()->assertJsonPath('choices.medication-0.decision', 'approve')->assertJsonPath('choices.medication-0.values.dose', '5 mg');
        $this->actingAs($s['user'])->putJson($url, ['choices' => (object) []])->assertOk();
    });

    it('validates the choices shape with lang-key messages', function () {
        $s = ubHttpScene();
        $url = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/choices';
        $tooMany = [];
        for ($i = 0; $i < 201; $i++) {
            $tooMany["k$i"] = ['decision' => 'reject'];
        }

        $r = $this->actingAs($s['user'])->putJson($url, ['choices' => ['medication-0' => ['decision' => 'maybe']]])->assertStatus(422);
        expect($r->json('errors.choices.medication-0.decision.0') ?? json_encode($r->json('errors')))->toContain('health.error.load');
        $this->actingAs($s['user'])->putJson($url, ['choices' => $tooMany])->assertStatus(422);
        $this->actingAs($s['user'])->putJson($url, ['choices' => ['medication-0' => ['decision' => 'approve', 'values' => ['dose' => ['x']]]]])->assertStatus(422);
        $this->actingAs($s['user'])->putJson($url, [])->assertStatus(422);
    });

    it('answers 409 not_ready for an item without a proposal', function () {
        $s = ubHttpScene();
        $url = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][1].'/choices';
        $this->actingAs($s['user'])->putJson($url, ['choices' => ['medication-0' => ['decision' => 'reject']]])->assertStatus(409)->assertExactJson(['reason' => 'not_ready']);
    });

    it('stores adjustments and validates ranges', function () {
        $s = ubHttpScene();
        $url = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/adjustments';

        $this->actingAs($s['user'])->putJson($url, ['brightness' => 120, 'contrast' => 80, 'bw' => true])->assertOk()
            ->assertExactJson(['adjustments' => ['brightness' => 120, 'contrast' => 80, 'bw' => true]]);
        foreach ([['brightness' => 49], ['brightness' => 151], ['contrast' => 49], ['contrast' => 201], ['bw' => 'maybe'], ['brightness' => 'x']] as $bad) {
            $this->actingAs($s['user'])->putJson($url, $bad + ['brightness' => 100, 'contrast' => 100, 'bw' => false])->assertStatus(422);
        }
    });

    it('sets the current item with 204', function () {
        $s = ubHttpScene();
        $this->actingAs($s['user'])->putJson(ubHttpBase($s['person']).'/'.$s['batch'].'/current', ['itemId' => $s['items'][1]])->assertNoContent();
        expect(DB::table('medical_upload_batches')->where('id', $s['batch'])->value('current_item_id'))->toBe($s['items'][1]);
    });
});

describe('rescan', function () {
    it('answers 202 and starts the run at once', function () {
        Bus::fake();
        $s = ubHttpScene();
        $this->actingAs($s['user'])->postJson(ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/rescan', [])->assertStatus(202);

        expect(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->value('rescan_count'))->toBe(1);
        Bus::assertDispatched(RunExtractionJob::class);
    });

    it('answers 409 limit, done, consent_off and notice_required', function () {
        Bus::fake();
        $s = ubHttpScene();
        $url = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/rescan';

        DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_count' => 5]);
        $this->actingAs($s['user'])->postJson($url, [])->assertStatus(409)->assertExactJson(['reason' => 'limit']);
        DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_count' => 0, 'state' => 'done']);
        $this->actingAs($s['user'])->postJson($url, [])->assertStatus(409)->assertExactJson(['reason' => 'done']);
        DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['state' => 'sent']);
        DB::table('medical_subjects')->where('id', $s['person']->id)->update(['ai_consent' => false]);
        $this->actingAs($s['user'])->postJson($url, [])->assertStatus(409)->assertExactJson(['reason' => 'consent_off']);
        DB::table('medical_subjects')->where('id', $s['person']->id)->update(['ai_consent' => true]);
        DB::table('ai_notice_acks')->delete();
        $this->actingAs($s['user'])->postJson($url, [])->assertStatus(409)->assertExactJson(['reason' => 'notice_required', 'providers' => ['codex']]);
        Bus::assertNotDispatched(RunExtractionJob::class);
    });
});

describe('continue', function () {
    it('saves the approved findings, moves on and starts the next document', function () {
        Bus::fake();
        $s = ubHttpScene();
        $base = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0];
        $this->actingAs($s['user'])->putJson($base.'/choices', ['choices' => ['medication-0' => ['decision' => 'approve']]])->assertOk();

        $this->actingAs($s['user'])->postJson($base.'/continue')->assertOk()
            ->assertExactJson(['result' => 'continued', 'nextItemId' => $s['items'][1], 'approved' => 1, 'rejected' => 0]);

        expect(DB::table('medical_medications')->where('subject_id', $s['person']->id)->count())->toBe(1);
        Bus::assertDispatched(RunExtractionJob::class);
    });

    it('answers 409 not_ready while a run is active', function () {
        Bus::fake();
        $s = ubHttpScene();
        DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_requested_at' => now()]);
        $this->actingAs($s['user'])->postJson(ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0].'/continue')->assertStatus(409)->assertExactJson(['reason' => 'not_ready']);
    });

    it('answers 422 keyed entries.<key>.<field> and saves nothing', function () {
        Bus::fake();
        $s = ubHttpScene();
        $base = ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][0];
        $this->actingAs($s['user'])->putJson($base.'/choices', ['choices' => ['medication-0' => ['decision' => 'approve', 'values' => ['started_on' => 'not-a-date']]]])->assertOk();

        $r = $this->actingAs($s['user'])->postJson($base.'/continue')->assertStatus(422);
        expect(array_keys($r->json('errors')))->toContain('entries.medication-0.started_on')
            ->and(DB::table('medical_medications')->count())->toBe(0)
            ->and(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->value('state'))->not->toBe('done');
    });
});

describe('remove and delete', function () {
    it('removes one item with its files and answers 204', function () {
        Bus::fake();
        $s = ubHttpScene();
        $path = (string) DB::table('medical_documents')->where('id', $s['docs'][1])->value('path');
        expect(Storage::disk('medical')->exists($path))->toBeTrue();

        $this->actingAs($s['user'])->deleteJson(ubHttpBase($s['person']).'/'.$s['batch'].'/items/'.$s['items'][1])->assertNoContent();

        expect(DB::table('medical_upload_batch_items')->where('id', $s['items'][1])->exists())->toBeFalse()
            ->and(Storage::disk('medical')->exists($path))->toBeFalse();
    });

    it('deletes the batch with the unfinished documents and their files', function () {
        $s = ubHttpScene();
        $paths = DB::table('medical_documents')->whereIn('id', $s['docs'])->pluck('path')->all();

        $this->actingAs($s['user'])->deleteJson(ubHttpBase($s['person']).'/'.$s['batch'])->assertOk()->assertJsonPath('removed', 2);

        expect(DB::table('medical_upload_batches')->where('status', 'open')->count())->toBe(0)
            ->and(DB::table('medical_documents')->whereIn('id', $s['docs'])->count())->toBe(0);
        foreach ($paths as $path) {
            expect(Storage::disk('medical')->exists($path))->toBeFalse();
        }
    });
});
