<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('marks every finance page, panel and settings screen no-store for an authorised member', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    $checked = 0;

    foreach (FinancePrivacy::surfaces() as $route) {
        foreach (FinancePrivacy::urls($route, $ctx) as $url) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($ctx['viewer'])->get($url);
            expect(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'))->toBeTrue("$url answered ".$response->getStatusCode().' without no-store');
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(10);
});

it('marks the import preview JSON and its refusals no-store', function () {
    $ctx = FinancePrivacy::scenario();
    $file = UploadedFile::fake()->createWithContent('statement.csv', (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv'));

    $ok = $this->actingAs($ctx['viewer'])->post('/finance/import/preview', ['file' => $file])->assertOk();
    $bad = $this->actingAs($ctx['viewer'])->post('/finance/import/preview', [])->assertStatus(422);

    expect((string) $ok->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $bad->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks 403 and 404 finance responses no-store', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    $none = User::factory()->withGrant(Module::Finance, AccessLevel::None)->create();

    foreach (['/finance', '/finance/transactions', '/settings/finance-accounts'] as $url) {
        $response = $this->actingAs($none)->get($url);
        expect($response->getStatusCode())->toBeIn([302, 403, 404]);
        expect(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'))->toBeTrue("$url for no access lacks no-store");
    }

    $missing = $this->actingAs($ctx['viewer'])->get('/finance/import/'.fake()->uuid());
    expect($missing->getStatusCode())->toBe(404)
        ->and((string) $missing->headers->get('Cache-Control'))->toContain('no-store');
});
