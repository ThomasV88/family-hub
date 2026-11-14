<?php

use App\Models\MedicalDocument;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
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
