<?php

namespace Tests\Support;

use RuntimeException;

class TestDatabaseSafety
{
    public static function assertIsolated(
        string $appEnvironment,
        string $defaultConnection,
        string $sqliteDatabase,
        ?string $sqliteUrl,
        string $analyticsDatabase,
        string $oldDatabase,
    ): void {
        if ($appEnvironment !== 'testing'
            || $defaultConnection !== 'sqlite'
            || $sqliteDatabase !== ':memory:'
            || filled($sqliteUrl)
            || $analyticsDatabase !== ':memory:'
            || $oldDatabase !== ':memory:') {
            throw new RuntimeException('Unsafe Pest database environment: all SQLite connections must be isolated in memory.');
        }
    }
}
