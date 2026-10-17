<?php

use Tests\Support\TestDatabaseSafety;

it('accepts only isolated in-memory Pest database connections', function () {
    TestDatabaseSafety::assertIsolated('testing', 'sqlite', ':memory:', null, ':memory:', ':memory:');

    expect(true)->toBeTrue();
});

it('rejects a persistent primary, analytics, or legacy database before tests run', function (array $connections) {
    expect(fn () => TestDatabaseSafety::assertIsolated(...$connections))
        ->toThrow(RuntimeException::class, 'Unsafe Pest database environment');
})->with([
    [['local', 'sqlite', ':memory:', null, ':memory:', ':memory:']],
    [['testing', 'sqlite', 'database/database.sqlite', null, ':memory:', ':memory:']],
    [['testing', 'sqlite', ':memory:', 'sqlite:///database/database.sqlite', ':memory:', ':memory:']],
    [['testing', 'sqlite', ':memory:', null, 'database/telegram_analytics.sqlite', ':memory:']],
    [['testing', 'sqlite', ':memory:', null, ':memory:', 'database/database.sqlite']],
]);
