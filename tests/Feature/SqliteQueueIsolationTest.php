<?php

use App\Services\Queue\SqliteQueueIsolationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class SqliteQueueIsolationProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

beforeEach(function (): void {
    $this->queueIsolationPrimary = tempnam(sys_get_temp_dir(), 'tris-queue-primary-');
    $this->queueIsolationDedicated = tempnam(sys_get_temp_dir(), 'tris-queue-dedicated-');
});

afterEach(function (): void {
    DB::purge('sqlite');
    DB::purge('sqlite_queue');

    foreach ([$this->queueIsolationPrimary, $this->queueIsolationDedicated] as $path) {
        if (! is_string($path)) {
            continue;
        }

        foreach ([$path, $path.'-journal', $path.'-wal', $path.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    CarbonImmutable::setTestNow();
});

function configureQueueIsolationFiles(): void
{
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => test()->queueIsolationPrimary,
        'database.connections.sqlite.url' => null,
        'database.connections.sqlite_queue.database' => test()->queueIsolationDedicated,
        'database.connections.sqlite_queue.url' => null,
        'database.connections.sqlite_queue.busy_timeout' => 5000,
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'sqlite_queue',
        'queue.connections.database.after_commit' => true,
        'queue.batching.database' => 'sqlite_queue',
        'queue.failed.database' => 'sqlite_queue',
    ]);

    DB::purge('sqlite');
    DB::purge('sqlite_queue');
}

function migrateQueueIsolationSchema(): void
{
    $primaryExitCode = Artisan::call('migrate', [
        '--database' => 'sqlite',
        '--path' => 'database/migrations/0001_01_01_000002_create_jobs_table.php',
        '--force' => true,
    ]);

    if ($primaryExitCode !== 0) {
        throw new RuntimeException('Primary queue schema fixture failed: '.Artisan::output());
    }

    $exitCode = Artisan::call('migrate', [
        '--database' => 'sqlite_queue',
        '--path' => 'database/migrations/queue',
        '--force' => true,
    ]);

    if ($exitCode !== 0) {
        throw new RuntimeException('Queue isolation schema migration failed: '.Artisan::output());
    }
}

function seedQueueIsolationRows(): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));

    $jobs = [
        [
            'id' => 41,
            'queue' => 'default',
            'payload' => '{"displayName":"ReadyFixture"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp - 10,
            'created_at' => now()->timestamp - 30,
        ],
        [
            'id' => 42,
            'queue' => 'default',
            'payload' => '{"displayName":"DelayedFixture"}',
            'attempts' => 2,
            'reserved_at' => null,
            'available_at' => now()->timestamp + 600,
            'created_at' => now()->timestamp - 20,
        ],
        [
            'id' => 43,
            'queue' => 'default',
            'payload' => '{"displayName":"ReservedFixture"}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp - 5,
            'available_at' => now()->timestamp - 15,
            'created_at' => now()->timestamp - 25,
        ],
    ];

    DB::connection('sqlite')->table('jobs')->insert($jobs);
    DB::connection('sqlite')->table('job_batches')->insert([
        'id' => 'batch-fixture',
        'name' => 'queue isolation fixture',
        'total_jobs' => 3,
        'pending_jobs' => 1,
        'failed_jobs' => 1,
        'failed_job_ids' => '[43]',
        'options' => null,
        'cancelled_at' => null,
        'created_at' => now()->timestamp - 25,
        'finished_at' => null,
    ]);
    DB::connection('sqlite')->table('failed_jobs')->insert([
        'id' => 9,
        'uuid' => '00000000-0000-0000-0000-000000000009',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"displayName":"FailedFixture"}',
        'exception' => 'Synthetic isolated test failure',
        'failed_at' => '2026-11-01 09:00:00',
    ]);
    DB::connection('sqlite')->table('sqlite_sequence')->where('name', 'jobs')->update(['seq' => 99]);
    DB::connection('sqlite')->table('sqlite_sequence')->where('name', 'failed_jobs')->update(['seq' => 77]);

    return [
        'jobs' => $jobs,
        'job_batches' => DB::connection('sqlite')->table('job_batches')->get()->map(fn (object $row): array => (array) $row)->all(),
        'failed_jobs' => DB::connection('sqlite')->table('failed_jobs')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

it('keeps the current physical queue path by default while routing batches and failures consistently', function (): void {
    expect(config('database.connections.sqlite_queue.database'))
        ->toBe(config('database.connections.sqlite.database'))
        ->and(config('queue.connections.database.connection'))->toBe('sqlite_queue')
        ->and(config('queue.batching.database'))->toBe('sqlite_queue')
        ->and(config('queue.failed.database'))->toBe('sqlite_queue')
        ->and(config('queue.connections.database.after_commit'))->toBeFalse();
});

it('enables after-commit dispatch when the dedicated queue database is explicitly configured', function (): void {
    $name = 'DB_QUEUE_DATABASE';
    $previousEnv = getenv($name);
    $previousEnvArray = $_ENV[$name] ?? null;
    $previousServer = $_SERVER[$name] ?? null;
    $queuePath = $this->queueIsolationDedicated;

    putenv($name.'='.$queuePath);
    $_ENV[$name] = $queuePath;
    $_SERVER[$name] = $queuePath;

    try {
        $queueConfig = require base_path('config/queue.php');

        expect($queueConfig['connections']['database']['connection'])->toBe('sqlite_queue')
            ->and($queueConfig['connections']['database']['after_commit'])->toBeTrue()
            ->and($queueConfig['batching']['database'])->toBe('sqlite_queue')
            ->and($queueConfig['failed']['database'])->toBe('sqlite_queue');
    } finally {
        $previousEnv === false ? putenv($name) : putenv($name.'='.$previousEnv);

        if ($previousEnvArray === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $previousEnvArray;
        }

        if ($previousServer === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $previousServer;
        }
    }
});

it('keeps the explicit queue SQLite path independent from the primary DB URL', function (): void {
    $values = [];
    foreach (['DB_QUEUE_DATABASE' => $this->queueIsolationDedicated, 'DB_URL' => 'sqlite:/primary/from-url.sqlite'] as $name => $value) {
        $values[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        $databaseConfig = require base_path('config/database.php');

        expect($databaseConfig['connections']['sqlite_queue']['database'])->toBe($this->queueIsolationDedicated)
            ->and($databaseConfig['connections']['sqlite_queue']['url'])->toBeNull();
    } finally {
        foreach ($values as $name => [$previousEnv, $previousArray, $previousServer]) {
            $previousEnv === false ? putenv($name) : putenv($name.'='.$previousEnv);

            if ($previousArray === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previousArray;
            }

            if ($previousServer === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previousServer;
            }
        }
    }
});

it('creates the dedicated queue file explicitly before a connection-scoped migration', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    DB::purge('sqlite_queue');
    unlink($this->queueIsolationDedicated);

    expect(Artisan::call('queue:sqlite-isolate', ['--prepare' => true]))
        ->toBe(0)
        ->and(is_file($this->queueIsolationDedicated))->toBeTrue();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($this->queueIsolationDedicated) & 0777)->toBe(0600);
    }

    migrateQueueIsolationSchema();

    expect(Schema::connection('sqlite_queue')->hasTable('jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite_queue')->hasTable('failed_jobs'))->toBeTrue();
});

it('reports a missing queue schema as blocked without creating tables during preview', function (): void {
    configureQueueIsolationFiles();
    Artisan::call('migrate', [
        '--database' => 'sqlite',
        '--path' => 'database/migrations/0001_01_01_000002_create_jobs_table.php',
        '--force' => true,
    ]);

    $report = app(SqliteQueueIsolationService::class)->preview();

    expect($report['safe_to_transfer'])->toBeFalse()
        ->and($report['blocking_reason'])->toBe('queue_isolation_schema_missing')
        ->and(Schema::connection('sqlite_queue')->hasTable('jobs'))->toBeFalse();
});

it('creates the queue schema only in the dedicated file and protects the file from public access', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });

    migrateQueueIsolationSchema();

    expect(Schema::connection('sqlite_queue')->hasTable('jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite_queue')->hasTable('job_batches'))->toBeTrue()
        ->and(Schema::connection('sqlite_queue')->hasTable('failed_jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('failed_jobs'))->toBeTrue()
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0);

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($this->queueIsolationDedicated) & 0777)->toBe(0600);
    }
});

it('refuses to install the isolation schema when sqlite_queue still aliases the primary file', function (): void {
    configureQueueIsolationFiles();
    config(['database.connections.sqlite_queue.database' => $this->queueIsolationPrimary]);
    DB::purge('sqlite_queue');
    $migration = require base_path('database/migrations/queue/2026_11_01_000000_create_sqlite_queue_tables.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'queue_isolation_requires_separate_files');
});

it('previews without writes and transfers ready delayed reserved batched and failed records exactly in both directions', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    $before = seedQueueIsolationRows();
    $service = app(SqliteQueueIsolationService::class);

    $preview = $service->preview();

    expect($preview)->toMatchArray([
        'safe_to_transfer' => true,
        'source_journal_mode' => 'delete',
        'target_journal_mode' => 'delete',
        'source_counts' => [
            'ready' => 1,
            'delayed' => 1,
            'reserved' => 1,
            'job_batches' => 1,
            'failed_jobs' => 1,
        ],
        'target_counts' => [
            'ready' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'job_batches' => 0,
            'failed_jobs' => 0,
        ],
    ])
        ->and(DB::connection('sqlite')->table('jobs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['jobs'])
        ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0);

    $moved = $service->transfer();

    expect($moved['source_counts']['ready'])->toBe(1)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('sqlite_queue')->table('jobs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['jobs'])
        ->and(DB::connection('sqlite_queue')->table('job_batches')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['job_batches'])
        ->and(DB::connection('sqlite_queue')->table('failed_jobs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['failed_jobs'])
        ->and(DB::connection('sqlite_queue')->table('sqlite_sequence')->where('name', 'jobs')->value('seq'))->toBeGreaterThanOrEqual(99)
        ->and(DB::connection('sqlite_queue')->table('sqlite_sequence')->where('name', 'failed_jobs')->value('seq'))->toBeGreaterThanOrEqual(77);

    $returned = $service->transfer(reverse: true);

    expect($returned['source_counts']['ready'])->toBe(1)
        ->and(DB::connection('sqlite')->table('jobs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['jobs'])
        ->and(DB::connection('sqlite')->table('job_batches')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['job_batches'])
        ->and(DB::connection('sqlite')->table('failed_jobs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before['failed_jobs'])
        ->and(DB::connection('sqlite')->table('sqlite_sequence')->where('name', 'jobs')->value('seq'))->toBeGreaterThanOrEqual(99)
        ->and(DB::connection('sqlite')->table('sqlite_sequence')->where('name', 'failed_jobs')->value('seq'))->toBeGreaterThanOrEqual(77)
        ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0);
});

it('refuses to transfer if either file is already in WAL mode without changing its mode', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    DB::connection('sqlite_queue')->statement('PRAGMA journal_mode = WAL');

    $report = app(SqliteQueueIsolationService::class)->preview();

    expect($report['safe_to_transfer'])->toBeFalse()
        ->and($report['blocking_reason'])->toBe('queue_isolation_journal_mode_not_delete')
        ->and($report['target_journal_mode'])->toBe('wal');
});

it('does not mutate queue rows when dry-run is requested or a second transfer targets a non-empty file', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    seedQueueIsolationRows();
    $service = app(SqliteQueueIsolationService::class);

    $service->transfer();

    expect(fn () => $service->transfer())
        ->toThrow(RuntimeException::class, 'queue_isolation_target_not_empty')
        ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(3)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(0);
});

it('rolls back every table when one attached database write fails', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    seedQueueIsolationRows();
    $trigger = "CREATE TRIGGER prevent_queue_transfer BEFORE INSERT ON jobs WHEN NEW.queue = '__tris_queue_isolation_lock__' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END";
    DB::connection('sqlite')->statement($trigger);
    DB::connection('sqlite_queue')->statement($trigger);

    expect(fn () => app(SqliteQueueIsolationService::class)->transfer())
        ->toThrow(RuntimeException::class)
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(3)
        ->and(DB::connection('sqlite')->table('job_batches')->count())->toBe(1)
        ->and(DB::connection('sqlite')->table('failed_jobs')->count())->toBe(1)
        ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('sqlite_queue')->table('job_batches')->count())->toBe(0)
        ->and(DB::connection('sqlite_queue')->table('failed_jobs')->count())->toBe(0);
});

it('requires an explicit quiesced acknowledgement before applying the transfer command', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    seedQueueIsolationRows();

    expect(Artisan::call('queue:sqlite-isolate', ['--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('--quiesced')
        ->and(DB::connection('sqlite')->table('jobs')->count())->toBe(3)
        ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0);
});

it('lets an isolated queue reserve and dispatch while a primary writer is held and vice versa', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });
    migrateQueueIsolationSchema();

    $primaryWriter = new PDO('sqlite:'.$this->queueIsolationPrimary);
    $primaryWriter->exec('PRAGMA busy_timeout = 0');
    $primaryWriter->exec('BEGIN IMMEDIATE');
    $primaryWriter->exec("INSERT INTO application_probe (value) VALUES ('web writer')");

    try {
        Queue::connection('database')->push(new SqliteQueueIsolationProbeJob);
        $reserved = Queue::connection('database')->pop();

        expect($reserved)->not->toBeNull()
            ->and(DB::connection('sqlite_queue')->table('jobs')->value('attempts'))->toBe(1);
    } finally {
        $primaryWriter->exec('ROLLBACK');
    }

    $queueWriter = new PDO('sqlite:'.$this->queueIsolationDedicated);
    $webWriter = new PDO('sqlite:'.$this->queueIsolationPrimary);
    $pollerWriter = new PDO('sqlite:'.$this->queueIsolationPrimary);
    $queueWriter->exec('PRAGMA busy_timeout = 0');
    $queueWriter->exec('BEGIN IMMEDIATE');
    $queueWriter->exec("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{}', 0, 1, 1)");

    try {
        $webWriter->exec("INSERT INTO application_probe (value) VALUES ('web writer')");
        $pollerWriter->exec("INSERT INTO application_probe (value) VALUES ('poller writer')");

        expect(DB::connection('sqlite')->table('application_probe')->count())->toBe(2);
    } finally {
        $queueWriter->exec('ROLLBACK');
    }
});

it('does not expose an isolated job until the primary database transaction commits', function (): void {
    configureQueueIsolationFiles();
    migrateQueueIsolationSchema();

    DB::connection('sqlite')->beginTransaction();

    try {
        Queue::connection('database')->push(new SqliteQueueIsolationProbeJob);

        expect(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0);

        DB::connection('sqlite')->commit();
    } catch (Throwable $exception) {
        DB::connection('sqlite')->rollBack();

        throw $exception;
    }

    expect(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(1);
});

it('prevents schema rollback from dropping queue history or pending work', function (): void {
    configureQueueIsolationFiles();
    Schema::connection('sqlite')->create('application_probe', fn (Blueprint $table) => $table->id());
    migrateQueueIsolationSchema();
    DB::connection('sqlite_queue')->table('failed_jobs')->insert([
        'uuid' => '00000000-0000-0000-0000-000000000008',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"displayName":"PreservedFixture"}',
        'exception' => 'Synthetic history',
        'failed_at' => '2026-11-01 09:00:00',
    ]);
    $migration = require base_path('database/migrations/queue/2026_11_01_000000_create_sqlite_queue_tables.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'queue_isolation_tables_not_empty')
        ->and(DB::connection('sqlite_queue')->table('failed_jobs')->count())->toBe(1);
});
