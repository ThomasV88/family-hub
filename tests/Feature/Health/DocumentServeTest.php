<?php

use App\Access\AccessLevel;
use App\Models\AuditEntry;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
});

/** @return array{0: User, 1: MedicalSubject} */
function serveViewer(AccessLevel $level = AccessLevel::View): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::View);

    return [$user, MedicalFixtures::subject([], [[$user, $level]])];
}

function serveImage(MedicalSubject $person, string $title = 'Scan'): MedicalDocument
{
    $document = MedicalDocument::factory()->image()->create(['subject_id' => $person->id, 'title' => $title]);
    Storage::disk('medical')->put($document->thumb_path, ImageFixtures::jpeg());
    Storage::disk('medical')->put($document->path, ImageFixtures::jpeg());

    return $document;
}

function servePdf(MedicalSubject $person, string $title = 'Scan', ?string $bytes = null): MedicalDocument
{
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id, 'title' => $title]);
    Storage::disk('medical')->put($document->path, $bytes ?? MedicalFileFixtures::pdf());

    return $document;
}

function previewUrl(string $person, string $document, string $variant = 'thumb'): string
{
    return "/health/people/{$person}/documents/{$document}/preview/{$variant}";
}

function fileUrl(string $person, string $document): string
{
    return "/health/people/{$person}/documents/{$document}/file";
}

function binaryBody($response): string
{
    return $response->baseResponse instanceof BinaryFileResponse ? (string) $response->streamedContent() : (string) $response->getContent();
}

it('serves an image preview inline with hardened headers, revalidation and a 304', function (string $variant) {
    [$user, $person] = serveViewer();
    $document = serveImage($person);

    $response = $this->actingAs($user)->get(previewUrl($person->id, $document->id, $variant))->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'");
    expect((string) $response->headers->get('Vary'))->toContain('Cookie');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('no-store')
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{32}'.$variant.'"$/');
    $body = binaryBody($response);
    expect(substr($body, 0, 2))->toBe("\xFF\xD8")->and($body)->not->toContain('Exif');

    $etag = $response->headers->get('ETag');
    $again = $this->actingAs($user)->get(previewUrl($person->id, $document->id, $variant), ['If-None-Match' => $etag]);
    expect($again->getStatusCode())->toBe(304)
        ->and($again->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('no-store');
})->with(['thumb', 'view']);

it('refuses everything else with the identical 403, and never a 304 for a hidden document', function () {
    [$user, $person] = serveViewer();
    $pdf = servePdf($person);
    $heic = MedicalDocument::factory()->heic()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($heic->path, MedicalFileFixtures::heic());
    $image = serveImage($person);
    $hiddenPerson = MedicalFixtures::subject();
    $hidden = serveImage($hiddenPerson, 'ZxCanaryMed hidden');
    $etag = $this->actingAs($user)->get(previewUrl($person->id, $image->id))->headers->get('ETag');

    $reference = $this->actingAs($user)->get(previewUrl($person->id, (string) Str::uuid()));
    $reference->assertForbidden();
    $urls = [
        'pdf' => previewUrl($person->id, $pdf->id),
        'heic' => previewUrl($person->id, $heic->id),
        'variant full' => previewUrl($person->id, $image->id, 'full'),
        'variant x' => previewUrl($person->id, $image->id, 'x'),
        'hidden person' => previewUrl($hiddenPerson->id, $hidden->id),
        'visible person, hidden document' => previewUrl($person->id, $hidden->id),
        'malformed document' => previewUrl($person->id, 'abc'),
        'malformed person' => previewUrl('abc', $image->id),
    ];
    foreach ($urls as $label => $url) {
        foreach ([[], ['If-None-Match' => $etag]] as $headers) {
            $response = $this->actingAs($user)->get($url, $headers);
            expect($response->getStatusCode())->toBe(403, $label)
                ->and(MedicalPrivacy::normalise($response))->toBe(MedicalPrivacy::normalise($reference), $label)
                ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        }
    }
});

it('refuses a person the viewer cannot see even with the real ETag of that file', function () {
    [$owner, $person] = serveViewer();
    $document = serveImage($person);
    $etag = $this->actingAs($owner)->get(previewUrl($person->id, $document->id))->headers->get('ETag');

    $stranger = User::factory()->create();
    MedicalFixtures::grantHealth($stranger, AccessLevel::View);
    app('auth')->forgetGuards();
    $this->actingAs($stranger)->get(previewUrl($person->id, $document->id), ['If-None-Match' => $etag])->assertForbidden();
    $this->actingAs($stranger)->get(fileUrl($person->id, $document->id))->assertForbidden();
});

it('serves a PDF as a no-store attachment with an ASCII slug name', function () {
    [$user, $person] = serveViewer();
    $document = servePdf($person, "Kontrola ZxCanaryMed č. 2/3\r\n\"x\"", MedicalFileFixtures::pdfWithScriptTail());

    $response = $this->actingAs($user)->get(fileUrl($person->id, $document->id))->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'");
    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($disposition)->toStartWith('attachment;')->toContain('kontrola-zxcanarymed-c-23-x.pdf')
        ->and($disposition)->not->toContain("\r")->not->toContain("\n")
        ->and(binaryBody($response))->toContain('<script>')
        ->and($response->headers->get('ETag'))->toBeNull();
    foreach ($response->headers->allPreserveCase() as $values) {
        foreach ($values as $value) {
            expect((string) $value)->not->toContain($document->path)->not->toMatch('/[0-9A-Z]{26}/');
        }
    }
});

it('falls back to a generic file name and serves HEIC and image downloads as attachments', function () {
    [$user, $person] = serveViewer();
    $heic = MedicalDocument::factory()->heic()->create(['subject_id' => $person->id, 'title' => '###']);
    Storage::disk('medical')->put($heic->path, MedicalFileFixtures::heic());
    $image = serveImage($person, 'Blood test');

    $h = $this->actingAs($user)->get(fileUrl($person->id, $heic->id))->assertOk();
    expect($h->headers->get('Content-Type'))->toBe('image/heic')
        ->and($h->headers->get('Content-Disposition'))->toContain('attachment')->toContain('document.heic')
        ->and($h->headers->get('Cache-Control'))->toBe('no-store, private');

    $i = $this->actingAs($user)->get(fileUrl($person->id, $image->id))->assertOk();
    expect($i->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($i->headers->get('Content-Disposition'))->toContain('attachment')->toContain('blood-test.jpg')
        ->and(binaryBody($i))->toBe(ImageFixtures::jpeg());
});

it('answers the same 403 when the file is missing on disk', function () {
    [$user, $person] = serveViewer();
    $document = servePdf($person);
    Storage::disk('medical')->delete($document->path);
    $image = serveImage($person);
    Storage::disk('medical')->delete($image->thumb_path);

    $reference = $this->actingAs($user)->get(fileUrl($person->id, (string) Str::uuid()));
    $missing = $this->actingAs($user)->get(fileUrl($person->id, $document->id));
    $missingThumb = $this->actingAs($user)->get(previewUrl($person->id, $image->id));
    expect($missing->getStatusCode())->toBe(403)->and(MedicalPrivacy::normalise($missing))->toBe(MedicalPrivacy::normalise($reference))
        ->and($missingThumb->getStatusCode())->toBe(403);
});

it('serves an uploaded photo without any EXIF segment', function () {
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);
    $created = $this->actingAs($user)->post(
        "/health/people/{$person->id}/documents",
        ['file' => UploadedFile::fake()->createWithContent('p.jpg', ImageFixtures::jpegWithExif())],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('document.id');

    foreach (['thumb', 'view'] as $variant) {
        $body = binaryBody($this->actingAs($user)->get(previewUrl($person->id, $created, $variant))->assertOk());
        expect($body)->not->toContain('Exif')->and(strpos($body, "\xFF\xE1"))->toBeFalse();
    }
});

it('audits reads with the subject and document id, never the thumbnails, and throttles preview and file', function () {
    [$user, $person] = serveViewer();
    $document = serveImage($person, 'Secret title');

    $this->actingAs($user)->get("/health/people/{$person->id}")->assertOk();
    $this->actingAs($user)->get(previewUrl($person->id, $document->id))->assertOk();
    $this->actingAs($user)->get(fileUrl($person->id, $document->id))->assertOk();

    $rows = AuditEntry::where('event', 'health_read')->orderBy('id')->get();
    expect($rows->map->meta->all())->toBe([
        ['subject' => $person->id],
        ['subject' => $person->id, 'document' => $document->id],
    ]);
    expect(json_encode($rows->map->meta))->not->toContain('Secret title');

    foreach (range(1, 40) as $i) {
        $status = $this->actingAs($user)->get(fileUrl($person->id, $document->id))->getStatusCode();
    }
    expect($status)->toBe(429);
});
