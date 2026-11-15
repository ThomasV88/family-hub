<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
function pageViewer(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::View);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::View]])];
}

function pageDoc(MedicalSubject $person, string $bytes): MedicalDocument
{
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($document->path, $bytes);

    return $document;
}

function pageUrl(string $person, string $document, string|int $page): string
{
    return "/health/people/{$person}/documents/{$document}/pages/{$page}";
}

/** @return list<string> */
function allFiles(): array
{
    $files = [];
    foreach ([storage_path(), Storage::disk('medical')->path('')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && ! str_contains($f->getPathname(), '/logs/') && ! str_contains($f->getPathname(), '/framework/cache/')) {
                $files[] = $f->getPathname().'|'.$f->getSize();
            }
        }
    }
    sort($files);

    return $files;
}

it('renders each page of a PDF as an uncacheable JPEG and writes no file', function () {
    [$user, $person] = pageViewer();
    $document = pageDoc($person, MedicalFileFixtures::pdfPages(3));
    $before = allFiles();

    foreach ([1, 2, 3] as $n) {
        $response = $this->actingAs($user)->get(pageUrl($person->id, $document->id, $n))->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'");
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('inline')->toContain("page-{$n}.jpg")
            ->and(substr((string) $response->getContent(), 0, 2))->toBe("\xFF\xD8");
    }
    expect(allFiles())->toBe($before);
});

it('refuses every miss with the identical 403', function () {
    [$user, $person] = pageViewer();
    $pdf = pageDoc($person, MedicalFileFixtures::pdfPages(3));
    $heic = MedicalDocument::factory()->heic()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($heic->path, MedicalFileFixtures::heic());
    $image = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
    Storage::disk('medical')->put($image->path, ImageFixtures::jpeg());
    $hiddenPerson = MedicalFixtures::subject();
    $hidden = pageDoc($hiddenPerson, MedicalFileFixtures::pdfPages(3));

    $reference = $this->actingAs($user)->get(pageUrl($person->id, (string) Str::uuid(), 1));
    $reference->assertForbidden();
    $urls = [
        'page 0' => pageUrl($person->id, $pdf->id, 0),
        'page 4' => pageUrl($person->id, $pdf->id, 4),
        'page x' => pageUrl($person->id, $pdf->id, 'x'),
        'page 01' => pageUrl($person->id, $pdf->id, '01'),
        'page 1000' => pageUrl($person->id, $pdf->id, 1000),
        'heic' => pageUrl($person->id, $heic->id, 1),
        'image' => pageUrl($person->id, $image->id, 1),
        'hidden person' => pageUrl($hiddenPerson->id, $hidden->id, 1),
        'hidden document' => pageUrl($person->id, $hidden->id, 1),
        'malformed document' => pageUrl($person->id, 'abc', 1),
    ];
    foreach ($urls as $label => $url) {
        $response = $this->actingAs($user)->get($url);
        expect($response->getStatusCode())->toBe(403, $label)
            ->and(MedicalPrivacy::normalise($response))->toBe(MedicalPrivacy::normalise($reference), $label)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('answers the identical 403 for a PDF poppler cannot open, within the rasterizer timeout', function () {
    [$user, $person] = pageViewer();
    $reference = $this->actingAs($user)->get(pageUrl($person->id, (string) Str::uuid(), 1));
    $broken = pageDoc($person, "%PDF-1.4\n".str_repeat('garbage ', 50));
    $stub = pageDoc($person, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    $start = microtime(true);
    foreach ([$broken, $stub] as $document) {
        $response = $this->actingAs($user)->get(pageUrl($person->id, $document->id, 1));
        expect($response->getStatusCode())->toBe(403)
            ->and(MedicalPrivacy::normalise($response))->toBe(MedicalPrivacy::normalise($reference));
    }
    expect(microtime(true) - $start)->toBeLessThan(2 * ((float) config('ai.pdf.info_timeout') + (float) config('ai.pdf.timeout')));
});

it('stays under the throttle for ten rapid page requests', function () {
    [$user, $person] = pageViewer();
    $document = pageDoc($person, MedicalFileFixtures::pdfPages(1));

    foreach (range(1, 10) as $i) {
        expect($this->actingAs($user)->get(pageUrl($person->id, $document->id, 1))->getStatusCode())->toBe(200);
    }
});
