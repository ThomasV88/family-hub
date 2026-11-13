<?php

use App\Console\Commands\AuditPrune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('deletes rows older than 2 years and keeps newer ones', function () {
    DB::table('audit_logs')->insert([
        ['event' => 'login_success', 'occurred_at' => now()->subYears(2)->subDay()],
        ['event' => 'login_failed', 'occurred_at' => now()->subYears(2)->addDay()],
        ['event' => 'lockout', 'occurred_at' => now()],
    ]);

    $this->artisan('audit:prune')->assertExitCode(0);

    expect(DB::table('audit_logs')->pluck('event')->all())->toEqualCanonicalizing(['login_failed', 'lockout']);
});

it('refuses to run as the app role', function () {
    Artisan::registerCommand(new class extends AuditPrune
    {
        protected function currentRole(): string
        {
            return 'fh_app';
        }
    });
    DB::table('audit_logs')->insert(['event' => 'login_success', 'occurred_at' => now()->subYears(3)]);

    $this->artisan('audit:prune')
        ->expectsOutput('audit:prune must run with the owner role (migrate service)')
        ->assertExitCode(1);

    expect(DB::table('audit_logs')->count())->toBe(1);
});
