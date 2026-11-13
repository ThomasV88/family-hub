<?php

use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use Illuminate\Http\UploadedFile;

$skFixture = fn (): UploadedFile => UploadedFile::fake()->createWithContent(
    'statement.csv',
    (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv'),
);

$skSettings = [
    'delimiter' => ';', 'encoding' => 'WINDOWS-1250', 'decimalSeparator' => ',', 'groupingSeparator' => ' ',
    'dateFormat' => 'd.m.Y', 'headerRow' => 4, 'amountMode' => 'single',
    'columns' => [
        'date' => ['name' => 'Dátum', 'index' => 0],
        'description' => ['name' => 'Popis', 'index' => 1],
        'counterparty' => ['name' => 'Protistrana', 'index' => 2],
        'amount' => ['name' => 'Suma', 'index' => 3],
        'currency' => ['name' => 'Mena', 'index' => 4],
    ],
];

return [
    'finance.import.index' => [
        'method' => 'GET',
        'uri' => '/finance/import',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.import.preview' => [
        'method' => 'POST',
        'uri' => '/finance/import/preview',
        'data' => fn (array $users): array => ['file' => $skFixture()],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.import.store' => [
        'method' => 'POST',
        'uri' => '/finance/import',
        'data' => fn (array $users): array => [
            'file' => $skFixture(),
            'account_id' => FinanceAccount::factory()->withMembers($users['parent'])->create()->id,
            'settings' => $skSettings,
        ],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.import.show' => [
        'method' => 'GET',
        'uri' => '/finance/import/{batch}',
        'params' => fn (array $users): array => [
            'batch' => FinanceImportBatch::factory()
                ->forAccount(FinanceAccount::factory()->withMembers($users['parent'])->create(), $users['parent'])
                ->withRows(1, 'Matrix row')->create()->id,
        ],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
