<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    DB::purge('analytics');
});

it('builds local primary and analytics schemas in order without losing existing rows', function () {
    $telegramBaseMigrations = [
        'database/migrations/2026_05_21_082853_create_telegram_chats_table.php',
        'database/migrations/2026_05_21_082854_create_telegram_topics_table.php',
        'database/migrations/2026_05_21_082855_create_telegram_users_table.php',
        'database/migrations/2026_05_21_082857_create_telegram_messages_table.php',
        'database/migrations/2026_05_21_082906_create_telegram_attachments_table.php',
        'database/migrations/2026_05_21_101806_fix_telegram_chats_table.php',
    ];

    expect(Artisan::call('migrate', ['--database' => 'analytics', '--path' => $telegramBaseMigrations]))->toBe(0);

    $pendingAnalyticsMigration = database_path('migrations/2026_11_01_000000_add_source_bot_to_telegram_operational_observations.php');
    $previousMigrations = collect(glob(database_path('migrations/*.php')))
        ->filter(fn (string $path): bool => strcmp($path, $pendingAnalyticsMigration) < 0)
        ->map(fn (string $path): string => 'database/migrations/'.basename($path))
        ->values()
        ->all();

    expect(Artisan::call('migrate', ['--database' => 'sqlite', '--path' => $previousMigrations]))->toBe(0);
    expect(Schema::hasTable('coachings'))->toBeTrue()
        ->and(Schema::connection('analytics')->hasColumn('telegram_topics', 'apartment_id'))->toBeTrue()
        ->and(Schema::connection('analytics')->hasColumn('telegram_operational_observations', 'source_bot'))->toBeFalse();

    $employeeId = DB::table('users')->insertGetId([
        'name' => 'Local fixture',
        'status' => 'approved',
        'birthday' => '1990-01-01',
        'work_started_at' => '2020-01-01',
    ]);
    expect(Artisan::call('migrate', ['--database' => 'sqlite']))->toBe(0)
        ->and(Artisan::call('migrate', ['--database' => 'sqlite']))->toBe(0)
        ->and(Schema::connection('analytics')->hasColumn('telegram_operational_observations', 'source_bot'))->toBeTrue()
        ->and(DB::table('users')->where('id', $employeeId)->value('name'))->toBe('Local fixture');

    $this->withoutVite();
    $this->actingAs(User::findOrFail($employeeId));
    $this->get(route('page-profile'))->assertOk();
    $this->get(route('page-profile.calendar'))->assertOk();
    $this->get(route('page-profile.checks'))->assertOk();

    DB::purge('analytics');
    expect(Schema::connection('analytics')->hasTable('telegram_operational_observations'))->toBeFalse();
    $analyticsMigrations = [
        ...$telegramBaseMigrations,
        'database/migrations/2026_07_31_161000_create_telegram_assistant_requests_tables.php',
        'database/migrations/2026_09_16_000000_create_telegram_operational_event_ledger.php',
        'database/migrations/2026_09_20_000000_add_apartment_context_to_telegram_operational_events.php',
        'database/migrations/2026_11_01_000000_add_source_bot_to_telegram_operational_observations.php',
    ];

    expect(Artisan::call('migrate', ['--database' => 'analytics', '--path' => $analyticsMigrations]))->toBe(0)
        ->and(Schema::connection('analytics')->hasColumn('telegram_operational_observations', 'source_bot'))->toBeTrue()
        ->and(DB::table('users')->where('id', $employeeId)->value('name'))->toBe('Local fixture');
});
