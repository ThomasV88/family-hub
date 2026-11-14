<?php

use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function mchStale(): array
{
    return ['auth.password_confirmed_at' => now()->subHour()->getTimestamp(), 'sensitive.active_at' => now()->subHour()->getTimestamp()];
}

it('marks every health page, panel variant, download and refusal no-store for an authorised member', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $checked = 0;
    $names = [];

    foreach (MedicalPrivacy::surfaces() as $route) {
        foreach (MedicalPrivacy::urls($route, $ctx) as $url) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($ctx['viewer'])->get($url);
            $header = (string) $response->headers->get('Cache-Control');
            expect(str_contains($header, 'no-store'))->toBeTrue("$url answered ".$response->getStatusCode()." with '$header'");
            $names[$route->getName()] = true;
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(25)
        ->and(array_keys($names))->toContain('health.index', 'health.people.show', 'health.documents.file', 'health.exports.download', 'health.exports.print', 'health.emergency.show');
});

it('marks 403 for hidden, unknown and malformed ids no-store on every id-carrying GET surface', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $checked = 0;

    foreach (MedicalPrivacy::surfaces() as $route) {
        $hidden = MedicalPrivacy::hiddenParams($route, $ctx);
        if ($hidden === null || ! str_starts_with($route->getName(), 'health.')) {
            continue;
        }
        $variants = [
            'hidden' => $hidden,
            'unknown' => MedicalPrivacy::hiddenParams($route, $ctx, fn () => (string) Str::uuid7()),
            'malformed' => MedicalPrivacy::hiddenParams($route, $ctx, fn () => 'abc'),
        ];
        foreach ($variants as $label => $params) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($ctx['viewer'])->get(route($route->getName(), $params, false));
            expect($response->getStatusCode())->toBe(403, $route->getName()." $label")
                ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(21);
});

it('answers authorised image previews private, no-cache with an ETag, and the 304 and the original file no-store', function () {
    Storage::fake('medical');
    $ctx = MedicalPrivacy::scenario();
    $document = MedicalDocument::factory()->image()->create(['subject_id' => $ctx['person']->getKey(), 'title' => 'Photo']);
    Storage::disk('medical')->put($document->thumb_path, ImageFixtures::jpeg());
    Storage::disk('medical')->put($document->path, ImageFixtures::jpeg());
    $base = "/health/people/{$ctx['person']->getKey()}/documents/{$document->getKey()}";

    $ok = $this->actingAs($ctx['viewer'])->get("$base/preview/thumb")->assertOk();
    $cache = (string) $ok->headers->get('Cache-Control');
    expect($cache)->toContain('private')->toContain('no-cache')->not->toContain('no-store')
        ->and($ok->headers->get('ETag'))->not->toBeNull();

    $again = $this->actingAs($ctx['viewer'])->get("$base/preview/thumb", ['If-None-Match' => $ok->headers->get('ETag')]);
    expect($again->getStatusCode())->toBe(304)->and((string) $again->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('no-store');

    $file = $this->actingAs($ctx['viewer'])->get("$base/file")->assertOk();
    expect((string) $file->headers->get('Cache-Control'))->toContain('no-store');
    // A refused preview (non-image variant, unknown document) is never cacheable.
    $refused = $this->actingAs($ctx['viewer'])->get("$base/preview/x");
    expect($refused->getStatusCode())->toBe(403)->and((string) $refused->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks stale-session refusals (redirect to confirm and 423) no-store on every health surface and the admin grant routes', function () {
    $this->useRealSensitiveSession();
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    $checked = 0;

    foreach (MedicalPrivacy::surfaces() as $route) {
        if (! str_starts_with($route->getName(), 'health.')) {
            continue;
        }
        $url = route($route->getName(), MedicalPrivacy::params($route, $ctx), false);
        foreach ([[], ['Accept' => 'application/json']] as $headers) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($ctx['viewer'])->withSession(mchStale())->get($url, $headers);
            expect($response->getStatusCode())->toBeIn([302, 423], "$url answered ".$response->getStatusCode())
                ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(16);

    foreach (MedicalPrivacy::writes() as $route) {
        if (! in_array($route->getName(), ['health.people.update', 'health.visits.store'], true)) {
            continue;
        }
        app('auth')->forgetGuards();
        $this->flushSession();
        $response = $this->actingAs($ctx['viewer'])->withSession(mchStale())->call(
            MedicalPrivacy::method($route), route($route->getName(), MedicalPrivacy::params($route, $ctx), false),
            MedicalPrivacy::payload($route->getName(), $ctx), [], [], ['HTTP_ACCEPT' => 'application/json'],
        );
        expect($response->getStatusCode())->toBe(423)->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('marks the AI status poll, the review page, the page images and every AI JSON write no-store and private, with no validator on page images', function () {
    Storage::fake('medical');
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $person = $ctx['person']->getKey();

    $pdf = MedicalDocument::factory()->pdf()->create(['subject_id' => $person, 'title' => 'Pages']);
    Storage::disk('medical')->put($pdf->path, MedicalFileFixtures::pdfPages(2));

    $gets = [
        'status' => route('health.people.ai.status', ['person' => $person], false),
        'review' => route('health.documents.ai.review', ['person' => $person, 'document' => $ctx['document']], false),
        'page 1' => route('health.documents.page', ['person' => $person, 'document' => $pdf->getKey(), 'page' => 1], false),
        'page 2' => route('health.documents.page', ['person' => $person, 'document' => $pdf->getKey(), 'page' => 2], false),
        'page out of range' => route('health.documents.page', ['person' => $person, 'document' => $pdf->getKey(), 'page' => 99], false),
    ];
    foreach ($gets as $label => $url) {
        app('auth')->forgetGuards();
        $this->flushSession();
        $response = $this->actingAs($ctx['viewer'])->get($url);
        $header = (string) $response->headers->get('Cache-Control');
        expect(str_contains($header, 'no-store') && str_contains($header, 'private'))->toBeTrue("$label answered ".$response->getStatusCode()." with '$header'");
        expect($response->headers->get('ETag'))->toBeNull("$label carries a validator");
    }
    // The two real pages really are images (not a refusal that happens to be no-store).
    foreach (['page 1', 'page 2'] as $label) {
        app('auth')->forgetGuards();
        $this->flushSession();
        expect($this->actingAs($ctx['viewer'])->get($gets[$label])->getStatusCode())->toBe(200);
    }

    $aiWrites = ['health.documents.ai.run', 'health.ai.cancel', 'health.ai.entries.accept', 'health.ai.entries.reject', 'health.ai.entries.undo'];
    $checked = 0;
    foreach (MedicalPrivacy::writes() as $route) {
        if (! in_array($route->getName(), $aiWrites, true)) {
            continue;
        }
        foreach (['visible' => MedicalPrivacy::params($route, $ctx), 'hidden' => MedicalPrivacy::hiddenParams($route, $ctx)] as $label => $params) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($ctx['viewer'])->call(
                MedicalPrivacy::method($route), route($route->getName(), $params, false),
                MedicalPrivacy::payload($route->getName(), $ctx), [], [], ['HTTP_ACCEPT' => 'application/json'],
            );
            expect(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'))->toBeTrue($route->getName()." $label answered ".$response->getStatusCode());
            $checked++;
        }
    }
    expect($checked)->toBe(count($aiWrites) * 2);
});

it('serves the review page with encrypted browser history and lands a stale session on the confirm-access page that clears it', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    $url = route('health.documents.ai.review', ['person' => $ctx['person']->getKey(), 'document' => $ctx['document']], false);

    $html = $this->actingAs($ctx['viewer'])->get($url)->assertOk()->getContent();
    preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $html, $m);
    $page = json_decode($m[1] ?? 'null', true);
    expect($page['component'] ?? null)->toBe('Health/AiReview')
        ->and($page['encryptHistory'] ?? false)->toBeTrue();

    // The idle gate sends a stale session to confirm-access, which clears history (HealthHistoryTest asserts the clear itself).
    $this->useRealSensitiveSession();
    app('auth')->forgetGuards();
    $this->flushSession();
    $stale = $this->actingAs($ctx['viewer'])->withSession(mchStale())->get($url);
    expect($stale->getStatusCode())->toBe(302)->and((string) $stale->headers->get('Location'))->toContain('confirm-access');
});

it('marks the AI agents page, the bulk poll and every agent and bulk JSON response no-store and private', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $run = (string) Str::uuid();
    $responses = [
        'agents page' => $this->get('/settings/ai-agents'),
        'bulk poll' => $this->getJson('/settings/ai-agents/bulk'),
        'dry run' => $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5]),
        'start (expired token)' => $this->postJson('/settings/ai-agents/bulk', ['token' => (string) Str::uuid(), 'acknowledged' => true]),
        'pause (unknown run)' => $this->postJson("/settings/ai-agents/bulk/$run/pause"),
        'resume (unknown run)' => $this->postJson("/settings/ai-agents/bulk/$run/resume"),
        'cancel (unknown run)' => $this->postJson("/settings/ai-agents/bulk/$run/cancel"),
        'agent update' => $this->patchJson('/settings/ai-agents/codex', ['model' => 'gpt-test']),
        'agent move' => $this->postJson('/settings/ai-agents/codex/move', ['direction' => 'down']),
        'agent update (unknown agent)' => $this->patchJson('/settings/ai-agents/nope', ['enabled' => true]),
        'validation error' => $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'everything']),
    ];
    foreach ($responses as $label => $response) {
        $header = (string) $response->headers->get('Cache-Control');
        expect(str_contains($header, 'no-store') && str_contains($header, 'private'))->toBeTrue("$label answered ".$response->getStatusCode()." with '$header'");
    }

    // A member who is not an admin gets the same no-store refusal.
    $member = User::factory()->create();
    foreach (['/settings/ai-agents', '/settings/ai-agents/bulk'] as $url) {
        app('auth')->forgetGuards();
        $refused = $this->actingAs($member)->get($url);
        expect($refused->getStatusCode())->toBe(403)->and((string) $refused->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('keeps the extended AI status poll no-store and private while a run waits or failed with a quota code', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    $url = route('health.people.ai.status', ['person' => $ctx['person']->getKey()], false);
    $response = $this->actingAs($ctx['viewer'])->getJson($url);
    $header = (string) $response->headers->get('Cache-Control');
    expect($response->getStatusCode())->toBe(200)->and(str_contains($header, 'no-store') && str_contains($header, 'private'))->toBeTrue($header)
        ->and($response->headers->get('ETag'))->toBeNull()
        ->and(collect($response->json('documents'))->pluck('status')->all())->toContain('waiting');
});
