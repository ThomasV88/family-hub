<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('raises on any UPDATE of an audit row', function () {
    DB::table('audit_logs')->insert(['event' => 'login_success']);

    expect(fn () => DB::table('audit_logs')->update(['event' => 'tampered']))->toThrow(QueryException::class, 'append-only');
});
