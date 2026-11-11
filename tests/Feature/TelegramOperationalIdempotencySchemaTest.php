<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.connections.analytics.database' => ':memory:']);
    DB::purge('analytics');

    Schema::connection('analytics')->create('telegram_operational_observations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('telegram_message_id');
        $table->string('source_revision_hash', 64);
        $table->string('evaluation_kind', 20)->default('message');
        $table->unique(
            ['telegram_message_id', 'source_revision_hash', 'evaluation_kind'],
            'telegram_operational_observations_revision_unique',
        );
    });

    DB::connection('analytics')->table('telegram_operational_observations')->insert([
        'telegram_message_id' => 7,
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message',
    ]);
});

afterEach(function () {
    DB::purge('analytics');
});

it('backfills legacy source and atomically scopes uniqueness to the source bot', function () {
    $migration = require database_path('migrations/2026_11_01_000000_add_source_bot_to_telegram_operational_observations.php');
    $migration->up();

    $table = DB::connection('analytics')->table('telegram_operational_observations');
    $legacy = $table->sole();

    expect($legacy->source_bot)->toBe('legacy');

    $table->insert([
        'telegram_message_id' => 7,
        'source_bot' => 'work',
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message',
    ]);
    $table->insert([
        'telegram_message_id' => 7,
        'source_bot' => 'analytics',
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message',
    ]);

    expect(fn () => $table->insert([
        'telegram_message_id' => 7,
        'source_bot' => 'analytics',
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message',
    ]))->toThrow(QueryException::class);
});
