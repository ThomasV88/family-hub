<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument} */
function docTextLeakScene(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $subject = MedicalFixtures::subject(['name' => 'Leak Person'], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Discharge']);
    DocumentTextFixtures::save($doc, DocumentTextFixtures::samplePages());

    return [$user, $subject, $doc];
}

function docTextLeakLogs(): string
{
    $out = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $file) {
        $out .= (string) file_get_contents($file);
    }

    return $out;
}

it('keeps the recognised text only in its own table', function () {
    [, , $doc] = docTextLeakScene();

    $row = DB::selectOne('select body, search::text as search from medical_document_texts where document_id = ?', [$doc->id]);
    expect($row->body)->toContain(DocumentTextFixtures::CANARY)
        ->and($row->search)->toContain(strtolower(DocumentTextFixtures::CANARY));
});

it('never puts the text into an export, the emergency card or the person page', function () {
    [$user, $subject] = docTextLeakScene();
    $id = $subject->id;
    $urls = [
        "/health/people/{$id}",
        "/health/people/{$id}/export",
        "/health/people/{$id}/export/print?scope=all&include_unverified=1",
        "/health/people/{$id}/export/download?scope=all&format=json&include_unverified=1",
        "/health/people/{$id}/export/download?scope=all&format=md&include_unverified=1",
        "/health/people/{$id}/emergency-card",
    ];

    foreach ($urls as $url) {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();
        $body = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
        expect($body)->not->toContain(DocumentTextFixtures::CANARY, $url)
            ->and($body)->not->toContain('Penicilín', $url);
    }
});

it('never puts the text into the Inertia props of the person page or the document panel', function () {
    [$user, $subject] = docTextLeakScene();

    $response = $this->actingAs($user)->get("/health/people/{$subject->id}")->assertOk();
    $props = json_encode($response->viewData('page')['props'] ?? null, JSON_UNESCAPED_UNICODE) ?: '';

    expect($props)->toContain('Leak Person')
        ->and($props)->not->toContain(DocumentTextFixtures::CANARY)
        ->and($props)->not->toContain('zażółć')
        ->and($props)->not->toContain('Penicil');
});

it('never puts the text into ai_calls, audit rows, jobs, failed jobs or the logs', function () {
    [$user, $subject] = docTextLeakScene();

    // Withdraw and re-grant consent through the real route, the one request that touches the text table.
    $this->actingAs($user)->patch("/health/people/{$subject->id}/ai-consent", ['value' => true]);
    $this->actingAs($user)->patch("/health/people/{$subject->id}/ai-consent", ['value' => false])->assertRedirect();
    expect(DB::table('medical_document_texts')->count())->toBe(0);

    [, , $doc] = docTextLeakScene();
    $sinks = json_encode([
        'ai_calls' => DB::table('ai_calls')->get(),
        'audit' => DB::table('audit_logs')->get(),
        'jobs' => DB::table('jobs')->get(),
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'extractions' => DB::table('medical_ai_extractions')->get(),
        'documents' => DB::table('medical_documents')->get(),
    ]) ?: '';

    expect($sinks)->not->toContain(DocumentTextFixtures::CANARY)
        ->and(docTextLeakLogs())->not->toContain(DocumentTextFixtures::CANARY)
        ->and(DB::table('medical_document_texts')->where('document_id', $doc->id)->count())->toBe(1);
});
