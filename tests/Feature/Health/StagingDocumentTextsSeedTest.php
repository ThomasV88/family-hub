<?php

use App\Models\MedicalAiExtraction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
    Storage::fake('medical');
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function stgTextSeed(): void
{
    test()->artisan('staging:seed --force')->assertExitCode(0);
}

/** @return object|null the text row of the document with this title fragment */
function stgTextRow(string $titlePart): ?object
{
    return DB::table('medical_document_texts as t')->join('medical_documents as d', 'd.id', '=', 't.document_id')
        ->where('d.title', 'like', "%{$titlePart}%")->first(['t.*', 'd.archived_at as doc_archived_at']);
}

function stgTextSearch(string $email, string $q, ?string $person = null): array
{
    $user = User::where('email', "{$email}@example.test")->firstOrFail();
    app('auth')->forgetGuards();
    app('cache')->store()->flush();
    $payload = ['q' => $q] + ($person === null ? [] : ['person' => $person]);

    return test()->actingAs($user)->postJson('/health/search', $payload)->assertOk()->json('items');
}

it('writes synthetic texts for every scenario through the store and the staging audit passes', function () {
    stgTextSeed();

    $rows = DB::table('medical_document_texts')->get();
    expect($rows)->toHaveCount(6)
        ->and(DB::table('medical_document_texts')->whereNotIn('started_by', User::where('email', 'like', '%@example.test')->select('id'))->count())->toBe(0);

    $sk = stgTextRow('krvných testov');
    expect($sk->body)->toContain('Žltačka')->toContain('ľadviny')->toContain('Penicilín')
        ->and($sk->pages_read)->toBe(3)->and($sk->complete)->toBeTrue()->and($sk->language)->toBe('sk');
    expect(stgTextRow('Prepúšťacia')->body)->toContain('zażółć')->toContain('Łukasz');
    expect(stgTextRow('Dlhá správa')->body)->toContain('paracetamol');

    $partial = stgTextRow('Dlhá správa');
    expect($partial->pages_read)->toBe(4)->and($partial->pages_total)->toBe(9)->and($partial->complete)->toBeFalse();

    $textOnly = stgTextRow('text uložený');
    $pages = explode("\x0c", $textOnly->body);
    expect($pages)->toHaveCount(3)->and($pages[1])->toBe('')->and($textOnly->complete)->toBeTrue();
    $run = MedicalAiExtraction::findOrFail($textOnly->extraction_id);
    expect($run->mode)->toBe('text')->and($run->status)->toBe('text_saved')->and($run->payload)->toBeNull();

    $twoStep = MedicalAiExtraction::findOrFail(stgTextRow('Prepúšťacia')->extraction_id);
    expect($twoStep->mode)->toBe('full')->and($twoStep->status)->toBe('ready')->and($twoStep->prompt_version)->toBe(2);

    expect(stgTextRow('Recept')->body)->toContain('Warfarín')
        ->and(stgTextRow('Archivovaný')->doc_archived_at)->not->toBeNull();

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('lets the demo parent find the visible texts and the hidden person (a parent may see) but not the archived document', function () {
    stgTextSeed();

    foreach (['zltacka', 'ladviny', 'zazolc', 'lukasz', 'paracetamolu', 'penicilin'] as $word) {
        expect(stgTextSearch('sk_sk.1', $word))->not->toBeEmpty("{$word} should be found");
    }
    expect(stgTextSearch('sk_sk.1', 'warfarin'))->not->toBeEmpty()
        ->and(stgTextSearch('sk_sk.1', 'betaloc'))->toBe([])
        // a word from the fourth page (the last one read) is found
        ->and(stgTextSearch('sk_sk.1', 'diary'))->not->toBeEmpty();
});

it('never lets a member without access to the hidden person find the text', function () {
    stgTextSeed();

    // the child member has Health view but no grant on the hidden person
    expect(stgTextSearch('sk_sk.2', 'warfarin'))->toBe([]);
});

it('purges the seeded texts on a reseed and leaves one text per seeded document', function () {
    stgTextSeed();
    $first = DB::table('medical_document_texts')->count();
    stgTextSeed();

    expect(DB::table('medical_document_texts')->count())->toBe($first)
        ->and(DB::table('medical_document_texts')->select('document_id')->distinct()->count())->toBe($first)
        ->and(DB::table('medical_document_texts')->whereNotIn('document_id', DB::table('medical_documents')->select('id'))->count())->toBe(0);
    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails the staging audit on a text row started by a real user', function () {
    stgTextSeed();
    $real = User::factory()->create(['email' => 'someone@real.sk']);
    DB::table('medical_document_texts')->limit(1)->update(['started_by' => $real->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_document_texts')->assertExitCode(1);
});

it('does not hold a recognised text for a person without consent', function () {
    stgTextSeed();

    expect(DB::table('medical_document_texts')->whereIn('subject_id', DB::table('medical_subjects')->where('ai_consent', false)->select('id'))->count())->toBe(0);
});
