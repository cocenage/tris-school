<?php

use App\Jobs\ProcessTelegramOperationalMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class QueueConnectionProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

afterEach(function (): void {
    DB::purge('queue_test');
    DB::purge('sqlite_queue');
    DB::purge('analytics');
    DB::purge('sqlite');
});

it('uses an immediate transaction for short analytics writes', function () {
    $database = tempnam(sys_get_temp_dir(), 'tris-analytics-lock-');
    $analytics = null;
    $competitor = null;

    try {
        expect(config('database.connections.analytics.transaction_mode'))->toBe('IMMEDIATE');
        config([
            'database.connections.analytics.database' => $database,
            'database.connections.analytics.busy_timeout' => 0,
        ]);
        DB::purge('analytics');
        Schema::connection('analytics')->create('lock_probe', function (Blueprint $table): void {
            $table->id();
        });

        $analytics = DB::connection('analytics');
        $analytics->beginTransaction();
        $competitor = new PDO('sqlite:'.$database);
        $competitor->exec('PRAGMA busy_timeout = 0');

        expect(fn () => $competitor->exec('BEGIN IMMEDIATE TRANSACTION'))
            ->toThrow(PDOException::class);
    } finally {
        if ($competitor instanceof PDO) {
            try {
                $competitor->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        if ($analytics && $analytics->transactionLevel() > 0) {
            $analytics->rollBack();
        }
        DB::purge('analytics');
        @unlink($database);
    }
});

it('does not consume or fail a queued job when a competing SQLite writer blocks reservation', function () {
    $database = tempnam(sys_get_temp_dir(), 'tris-queue-lock-');
    $writer = null;

    try {
        expect(config('queue.connections.database.connection'))->toBe('sqlite_queue')
            ->and(config('database.connections.sqlite_queue.transaction_mode'))->toBe('IMMEDIATE');
        config([
            'database.connections.sqlite_queue.database' => $database,
            'database.connections.sqlite_queue.busy_timeout' => 0,
            'queue.connections.database.connection' => 'sqlite_queue',
            'queue.failed.database' => 'sqlite_queue',
        ]);
        DB::purge('sqlite_queue');

        Schema::connection('sqlite_queue')->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('sqlite_queue')->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Queue::connection('database')->push(new QueueConnectionProbeJob);
        $writer = new PDO('sqlite:'.$database);
        $writer->exec('PRAGMA busy_timeout = 0');
        $writer->exec('BEGIN IMMEDIATE TRANSACTION');

        expect(fn () => Queue::connection('database')->pop())->toThrow(PDOException::class)
            ->and(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(1)
            ->and(DB::connection('sqlite_queue')->table('failed_jobs')->count())->toBe(0);

        $writer->exec('ROLLBACK');
        $writer = null;

        $job = Queue::connection('database')->pop();

        expect($job)->not->toBeNull()
            ->and(DB::connection('sqlite_queue')->table('jobs')->value('attempts'))->toBe(1)
            ->and(DB::connection('sqlite_queue')->table('failed_jobs')->count())->toBe(0);
    } finally {
        if ($writer instanceof PDO) {
            try {
                $writer->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        DB::purge('sqlite_queue');
        @unlink($database);
    }
});

it('keeps database queue delays on the dedicated SQLite connection', function () {
    $database = tempnam(sys_get_temp_dir(), 'tris-queue-delay-');
    Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00'));

    try {
        config([
            'database.connections.sqlite_queue.database' => $database,
            'queue.connections.database.connection' => 'sqlite_queue',
        ]);
        DB::purge('sqlite_queue');
        Schema::connection('sqlite_queue')->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Queue::connection('database')->later(now()->addMinute(), new QueueConnectionProbeJob);

        expect(Queue::connection('database')->pop())->toBeNull();

        Carbon::setTestNow(Carbon::parse('2026-10-29 12:01:01'));
        $job = Queue::connection('database')->pop();

        expect($job)->not->toBeNull()
            ->and(DB::connection('sqlite_queue')->table('jobs')->value('attempts'))->toBe(1);
    } finally {
        Carbon::setTestNow();
        DB::purge('sqlite_queue');
        @unlink($database);
    }
});

it('dispatches explicitly after-commit jobs only after the application transaction commits', function () {
    $database = tempnam(sys_get_temp_dir(), 'tris-queue-after-commit-');

    try {
        config([
            'database.connections.sqlite.database' => $database,
            'database.connections.sqlite_queue.database' => $database,
            'queue.connections.database.connection' => 'sqlite_queue',
        ]);
        DB::purge('sqlite');
        DB::purge('sqlite_queue');
        Schema::connection('sqlite_queue')->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        DB::connection('sqlite')->transaction(function (): void {
            Queue::connection('database')->push((new QueueConnectionProbeJob)->afterCommit());

            expect(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(0);
        });

        expect(DB::connection('sqlite_queue')->table('jobs')->count())->toBe(1);
    } finally {
        DB::purge('sqlite_queue');
        DB::purge('sqlite');
        @unlink($database);
    }
});

it('allows operational message jobs to retry transient SQLite contention', function () {
    $job = new ProcessTelegramOperationalMessage(1);

    expect($job->tries)->toBe(8)
        ->and($job->backoff())->toBe([30, 60, 120, 300, 600, 600, 600]);
});

it('configures a bounded busy timeout for the SQLite application and analytics connections', function () {
    config(['database.connections.analytics.database' => ':memory:']);
    DB::purge('analytics');
    DB::purge('sqlite');
    DB::purge('sqlite_queue');

    $sqliteTimeout = (int) DB::connection('sqlite')->getPdo()->query('PRAGMA busy_timeout')->fetchColumn();
    $queueTimeout = (int) DB::connection('sqlite_queue')->getPdo()->query('PRAGMA busy_timeout')->fetchColumn();
    $analyticsTimeout = (int) DB::connection('analytics')->getPdo()->query('PRAGMA busy_timeout')->fetchColumn();

    expect($sqliteTimeout)->toBe((int) config('database.connections.sqlite.busy_timeout'))
        ->toBeGreaterThan(0)
        ->and($queueTimeout)->toBe((int) config('database.connections.sqlite_queue.busy_timeout'))
        ->toBeGreaterThan(0)
        ->and($analyticsTimeout)->toBe((int) config('database.connections.analytics.busy_timeout'))
        ->toBeGreaterThan(0);
});

it('stores queued and failed jobs on the configured queue database without changing the application database', function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.queue_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'queue_test',
        'queue.batching.database' => 'queue_test',
        'queue.failed.database' => 'queue_test',
    ]);

    DB::purge('sqlite');
    DB::purge('queue_test');

    Schema::connection('sqlite')->create('application_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });
    Schema::connection('queue_test')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::connection('queue_test')->create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    DB::connection('sqlite')->table('application_probe')->insert(['value' => 'primary remains available']);
    Queue::connection('database')->push(new QueueConnectionProbeJob);
    app(FailedJobProviderInterface::class)->log(
        'database',
        'default',
        json_encode(['uuid' => '00000000-0000-0000-0000-000000000001'], JSON_THROW_ON_ERROR),
        new RuntimeException('fixture failure'),
    );

    expect(DB::connection('queue_test')->table('jobs')->count())->toBe(1)
        ->and(DB::connection('queue_test')->table('failed_jobs')->count())->toBe(1)
        ->and(Schema::connection('sqlite')->hasTable('jobs'))->toBeFalse()
        ->and(DB::connection('sqlite')->table('application_probe')->value('value'))->toBe('primary remains available');
});
