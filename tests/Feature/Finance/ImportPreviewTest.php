<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use App\Models\FinanceImportProfile;
use App\Models\User;
use Database\Factories\FinanceImportProfileFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fhPvFile(?string $bytes = null, string $name = 'statement.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        $bytes ?? (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv'),
    );
}

/** @return array{0: User, 1: FinanceAccount} */
function fhPvSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();

    return [$user, FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main'])];
}

/** @return list<string> every file below the given roots */
function fhPvFiles(array $roots): array
{
    $out = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

it('previews the SK fixture with the suggested encoding and separator', function () {
    [$user] = fhPvSetup();

    $json = $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile()])->assertOk()->json();

    expect($json['suggested'])->toBe(['encoding' => 'WINDOWS-1250', 'reason' => 'cp1250_bytes', 'delimiter' => ';'])
        ->and($json['encoding'])->toBe('WINDOWS-1250')
        ->and($json['delimiter'])->toBe(';')
        ->and($json['headerRow'])->toBe(4)
        ->and($json['headers'])->toBe(['Dátum', 'Popis', 'Protistrana', 'Suma', 'Mena'])
        ->and($json['sample'])->toHaveCount(5)
        ->and($json['sample'][0][1])->toBe('Zaplatené nájomné')
        ->and($json['recordCount'])->toBe(9);
});

it('re-previews with an explicit header row, and answers refusals as 422 codes', function () {
    [$user] = fhPvSetup();

    $json = $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile(), 'header_row' => 0])->assertOk()->json();
    expect($json['headers'])->toBe([])->and($json['recordCount'])->toBe(13);

    $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile(), 'encoding' => 'UTF-8'])
        ->assertStatus(422)->assertExactJson(['error' => 'decode_failed']);

    $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile(str_repeat('a', 2 * 1024 * 1024 + 1))])
        ->assertStatus(422)->assertExactJson(['error' => 'too_large']);

    $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile("PK\x03\x04".str_repeat("\0", 64), 'renamed.csv')])
        ->assertStatus(422)->assertExactJson(['error' => 'binary']);

    $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile('a', 'notes.pdf')])
        ->assertStatus(422)->assertExactJson(['error' => 'binary']);

    $this->actingAs($user)->post('/finance/import/preview', [])->assertStatus(422)->assertExactJson(['error' => 'empty']);

    $rows = "Dátum;Suma\n".str_repeat("01.01.2026;1,00\n", 5001);
    $this->actingAs($user)->post('/finance/import/preview', ['file' => fhPvFile($rows), 'encoding' => 'UTF-8'])
        ->assertStatus(422)->assertExactJson(['error' => 'too_many_rows']);
});

it('keeps no file in storage or the temp directory and logs no statement text', function () {
    [$user, $account] = fhPvSetup();
    $upload = fhPvFile();
    $upload2 = fhPvFile();
    $roots = [storage_path('app'), sys_get_temp_dir()];
    $before = fhPvFiles($roots);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $this->actingAs($user)->post('/finance/import/preview', ['file' => $upload])->assertOk();
    $afterPreview = fhPvFiles($roots);

    $this->actingAs($user)->post('/finance/import', [
        'file' => $upload2, 'account_id' => $account->id, 'settings' => FinanceImportProfileFactory::skSettings(),
    ])->assertStatus(303);
    $afterStore = fhPvFiles($roots);

    expect(array_values(array_diff($afterPreview, $before)))->toBe([])
        ->and(array_values(array_diff($afterStore, $before)))->toBe([])
        ->and(implode('', $logged))->not->toContain('nájomné');
});

it('answers a hidden account exactly like a random account id', function () {
    [$user] = fhPvSetup();
    $hidden = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();
    $send = fn (string $accountId) => $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $accountId, 'settings' => FinanceImportProfileFactory::skSettings(),
    ]);

    $a = $send($hidden->id)->assertSessionHasErrors(['account_id' => 'finance.error.account']);
    $b = $send((string) Str::uuid7())->assertSessionHasErrors(['account_id' => 'finance.error.account']);

    expect(session('errors')->getBag('default')->all())->toBe(['finance.error.account'])
        ->and(DB::table('finance_import_batches')->count())->toBe(0);
    expect($a->getStatusCode())->toBe($b->getStatusCode());
});

it('returns to the mapping with an error when a saved profile does not fit the file', function () {
    [$user, $account] = fhPvSetup();
    $settings = FinanceImportProfileFactory::skSettings();
    $settings['columns']['amount'] = ['name' => 'Betrag', 'index' => null];
    $profile = FinanceImportProfile::factory()->create(['settings' => $settings]);

    $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $account->id, 'profile_id' => $profile->id,
    ])->assertSessionHasErrors(['profile_id' => 'finance.imp.err_profile']);

    expect(DB::table('finance_import_batches')->count())->toBe(0);
});

it('stages with a saved profile', function () {
    [$user, $account] = fhPvSetup();
    $profile = FinanceImportProfile::factory()->create();

    $response = $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $account->id, 'profile_id' => $profile->id,
    ]);

    $batchId = DB::table('finance_import_batches')->value('id');
    $response->assertStatus(303)->assertRedirect('/finance/import/'.$batchId);
});

it('saves a family-wide profile from custom settings and redirects to the draft', function () {
    [$user, $account] = fhPvSetup();

    $response = $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $account->id, 'settings' => json_encode(FinanceImportProfileFactory::skSettings()),
        'save_profile' => '1', 'profile_name' => 'My bank',
    ]);

    $batchId = DB::table('finance_import_batches')->value('id');
    $response->assertStatus(303)->assertRedirect('/finance/import/'.$batchId);
    expect(DB::table('finance_import_profiles')->pluck('name')->all())->toBe(['My bank']);
    expect(json_decode((string) DB::table('finance_import_profiles')->value('settings'), true)['columns']['date']['name'])->toBe('Dátum');
    expect(DB::table('finance_import_profiles')->where('created_by', $user->id)->exists())->toBeTrue();

    // A taken name is refused and nothing more is staged.
    $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $account->id, 'settings' => FinanceImportProfileFactory::skSettings(),
        'save_profile' => true, 'profile_name' => 'my BANK',
    ])->assertSessionHasErrors(['profile_name' => 'finance.imp.err_profile_name']);
    expect(DB::table('finance_import_profiles')->count())->toBe(1)->and(DB::table('finance_import_batches')->count())->toBe(1);
});

it('refuses settings outside the allowlists and maps file refusals to error keys', function () {
    [$user, $account] = fhPvSetup();
    $bad = FinanceImportProfileFactory::skSettings();
    $bad['dateFormat'] = 'Y-d-m H:i';

    $this->actingAs($user)->post('/finance/import', ['file' => fhPvFile(), 'account_id' => $account->id, 'settings' => $bad])
        ->assertSessionHasErrors(['settings' => 'finance.imp.err_profile']);
    $this->actingAs($user)->post('/finance/import', ['file' => fhPvFile(str_repeat('a', 2 * 1024 * 1024 + 1)), 'account_id' => $account->id, 'settings' => FinanceImportProfileFactory::skSettings()])
        ->assertSessionHasErrors(['file' => 'finance.imp.err_size']);
    $this->actingAs($user)->post('/finance/import', ['file' => fhPvFile("PK\x03\x04abc"), 'account_id' => $account->id, 'settings' => FinanceImportProfileFactory::skSettings()])
        ->assertSessionHasErrors(['file' => 'finance.imp.err_type']);
    $utf8 = FinanceImportProfileFactory::skSettings();
    $utf8['encoding'] = 'UTF-8';
    $this->actingAs($user)->post('/finance/import', ['file' => fhPvFile(), 'account_id' => $account->id, 'settings' => $utf8])
        ->assertSessionHasErrors(['file' => 'finance.imp.err_decode']);
});

it('shows a draft with rows and counts to its creator only', function () {
    [$user, $account] = fhPvSetup();
    $account->members()->attach(($other = User::factory()->withPreset(Preset::Parent)->create())->id, ['created_at' => now()]);
    $this->actingAs($user)->post('/finance/import', [
        'file' => fhPvFile(), 'account_id' => $account->id, 'settings' => FinanceImportProfileFactory::skSettings(),
    ]);
    $batchId = (string) DB::table('finance_import_batches')->value('id');

    $this->actingAs($user)->get('/finance/import/'.$batchId)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Finance/ImportBatch', false)
        ->where('batch.status', 'draft')
        ->where('batch.accountName', 'Main')
        ->where('batch.currency', 'EUR')
        ->where('batch.counts', ['total' => 9, 'new' => 7, 'duplicate' => 0, 'probable' => 0, 'invalid' => 2])
        ->where('batch.result', null)
        ->has('batch.rows', 9)
        ->where('batch.rows.0.amountMinor', '-45000')
        ->where('batch.rows.0.state', 'new')
        ->where('batch.rows.0.include', true)
        ->where('batch.rows.7.state', 'invalid'));

    app('auth')->forgetGuards();
    $seen = $this->actingAs($other)->get('/finance/import/'.$batchId);
    app('auth')->forgetGuards();
    $random = $this->actingAs($other)->get('/finance/import/'.Str::uuid7());
    expect($seen->getStatusCode())->toBe(404)->and(FinancePrivacy::normalise($seen))->toBe(FinancePrivacy::normalise($random));
});

it('shows a committed batch with counts and no rows to anyone who sees the account', function () {
    [$user, $account] = fhPvSetup();
    $account->members()->attach(($other = User::factory()->withPreset(Preset::Parent)->create())->id, ['created_at' => now()]);
    $batch = FinanceImportBatch::factory()->forAccount($account, $user)->committed()->withRows(3)
        ->create(['added_count' => 2, 'matched_count' => 1, 'kept_count' => 0]);

    $this->actingAs($other)->get('/finance/import/'.$batch->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('batch.status', 'committed')
        ->where('batch.rows', [])
        ->where('batch.counts.total', 3)
        ->where('batch.result', ['added' => 2, 'matched' => 1, 'kept' => 0]));
});

it('lists the import page data and history of visible accounts only', function () {
    [$user, $account] = fhPvSetup();
    $hidden = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();
    FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create(['added_count' => 4]);
    FinanceImportBatch::factory()->forAccount($account, $user)->create();
    FinanceImportBatch::factory()->forAccount($hidden, User::factory()->create())->committed()->create();
    FinanceImportProfile::factory()->create(['name' => 'Saved']);

    $this->actingAs($user)->get('/finance/import')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Finance/Import', false)
        ->has('import.accounts', 1)
        ->where('import.accounts.0.currency', 'EUR')
        ->has('import.profiles', 1)
        ->where('import.profiles.0.name', 'Saved')
        ->where('import.profiles.0.settings.delimiter', ';')
        ->has('import.history', 1)
        ->where('import.history.0.addedCount', 4)
        ->where('import.history.0.status', 'committed')
        ->where('import.limits', ['maxBytes' => 2097152, 'maxRows' => 5000]));
});

it('forbids every import route for view-only users', function () {
    [, $account] = fhPvSetup();
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $batch = FinanceImportBatch::factory()->forAccount($account, $viewOnly)->create();

    $this->actingAs($viewOnly)->get('/finance/import')->assertForbidden();
    $this->actingAs($viewOnly)->post('/finance/import/preview', ['file' => fhPvFile()])->assertForbidden();
    $this->actingAs($viewOnly)->post('/finance/import', ['file' => fhPvFile(), 'account_id' => $account->id])->assertForbidden();
    $this->actingAs($viewOnly)->get('/finance/import/'.$batch->id)->assertForbidden();
});

it('declares a 3m body limit for the import routes in the nginx snippet', function () {
    $conf = (string) file_get_contents(base_path('docker/nginx/snippets/laravel-locations.conf'));

    expect($conf)->toContain('location ~ ^/finance/import(/preview)?$ {')
        ->and(substr_count($conf, 'client_max_body_size 3m;'))->toBe(1);
});
