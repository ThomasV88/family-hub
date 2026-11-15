<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function appRoleExists(): bool
{
    return DB::selectOne("select exists (select from pg_roles where rolname = 'fh_app') as e")->e;
}

it('does not let the app role update, delete or truncate audit rows', function (string $privilege) {
    if (! appRoleExists()) {
        $this->markTestSkipped('role fh_app absent');
    }

    $has = DB::selectOne("select has_table_privilege('fh_app', 'audit_logs', ?) as p", [$privilege])->p;
    expect($has)->toBeFalse();
})->with(['UPDATE', 'DELETE', 'TRUNCATE']);

it('lets the app role insert and select audit rows', function (string $privilege) {
    if (! appRoleExists()) {
        $this->markTestSkipped('role fh_app absent');
    }

    $has = DB::selectOne("select has_table_privilege('fh_app', 'audit_logs', ?) as p", [$privilege])->p;
    expect($has)->toBeTrue();
})->with(['INSERT', 'SELECT']);
