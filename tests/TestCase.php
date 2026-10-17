<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseSafety::assertIsolated(
            (string) config('app.env'),
            (string) config('database.default'),
            (string) config('database.connections.sqlite.database'),
            config('database.connections.sqlite.url'),
            (string) config('database.connections.analytics.database'),
            (string) config('database.connections.old_sqlite.database'),
        );
    }
}
