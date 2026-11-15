<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests run against the Postgres test database (family_hub_test) as the
| owner role, because RefreshDatabase needs DDL. See phpunit.xml.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
