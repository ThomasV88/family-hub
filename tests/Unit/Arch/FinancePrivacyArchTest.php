<?php

// Finance queries live only in App\Finance\Queries, and only the finance layers may use them or the finance models.
// Namespaces that do not exist yet match nothing, so later plans are covered the moment they add classes.
arch('finance queries are used only by the finance layers')
    ->expect('App\Finance\Queries')
    ->toOnlyBeUsedIn([
        'App\Finance',
        'App\Http\Controllers\Finance',
        'App\Http\Controllers\Settings\FinanceAccountsController',
        'App\Http\Requests\Finance',
        'App\Http\Requests\Settings\FinanceAccountRequest',
        'App\Console\Commands\PruneFinanceImports',
        'Tests\Support',
    ]);

arch('finance models are used only by the finance layers and factories')
    ->expect([
        'App\Models\FinanceAccount',
        'App\Models\FinanceCategory',
        'App\Models\FinanceTransaction',
        'App\Models\FinanceRecurring',
        'App\Models\FinanceRecurringMatch',
        'App\Models\FinanceMatchRejection',
        'App\Models\FinanceBudget',
        'App\Models\FinanceImportProfile',
        'App\Models\FinanceImportBatch',
        'App\Models\FinanceImportRow',
    ])
    ->toOnlyBeUsedIn([
        'App\Finance',
        'App\Http\Controllers\Finance',
        'App\Http\Controllers\Settings\FinanceAccountsController',
        'App\Http\Requests\Finance',
        'App\Http\Requests\Settings\FinanceAccountRequest',
        'App\Console\Commands\PruneFinanceImports',
        'App\Models\FinanceAccount',
        'App\Models\FinanceCategory',
        'App\Models\FinanceTransaction',
        'App\Models\FinanceRecurring',
        'App\Models\FinanceRecurringMatch',
        'App\Models\FinanceMatchRejection',
        'App\Models\FinanceBudget',
        'App\Models\FinanceImportProfile',
        'App\Models\FinanceImportBatch',
        'App\Models\FinanceImportRow',
        'Database\Factories',
        'Tests\Support',
    ]);

arch('the home page never touches finance queries or models')
    ->expect('App\Http\Controllers\HomeController')
    ->not->toUse([
        'App\Finance\Queries',
        'App\Models\FinanceAccount',
        'App\Models\FinanceCategory',
        'App\Models\FinanceTransaction',
        'App\Models\FinanceRecurring',
        'App\Models\FinanceRecurringMatch',
        'App\Models\FinanceMatchRejection',
        'App\Models\FinanceBudget',
        'App\Models\FinanceImportProfile',
        'App\Models\FinanceImportBatch',
        'App\Models\FinanceImportRow',
    ]);

arch('finance money is its own exact type, not the wishlist one')
    ->expect('App\Finance')
    ->not->toUse('App\Wishlist\Money');
