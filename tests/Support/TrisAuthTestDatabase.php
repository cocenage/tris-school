<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

final class TrisAuthTestDatabase
{
    public static function migrate(): void
    {
        TestDatabaseSafety::assertIsolated(
            (string) config('app.env'),
            (string) config('database.default'),
            (string) config('database.connections.sqlite.database'),
            config('database.connections.sqlite.url'),
            (string) config('database.connections.analytics.database'),
            (string) config('database.connections.old_sqlite.database'),
        );

        // These tests exercise the actual auth schema, not unrelated analytics
        // migrations whose base tables must be bootstrapped separately.
        // Each Laravel test application owns its own in-memory connection.
        if (Schema::hasTable('users')) {
            throw new \LogicException('Auth tests require a fresh in-memory users schema.');
        }

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_11_27_235900_add_tris_id_to_users_table.php',
            '2026_11_28_000000_add_access_registration_to_users_table.php',
            '2026_11_28_000100_create_device_link_requests_table.php',
        ] as $filename) {
            $migration = require database_path('migrations/'.$filename);
            $migration->up();
        }
    }
}
