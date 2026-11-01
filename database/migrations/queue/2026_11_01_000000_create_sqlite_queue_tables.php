<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['jobs', 'job_batches', 'failed_jobs'];

    public function up(): void
    {
        $this->assertDedicatedQueueDatabase();

        $schema = Schema::connection('sqlite_queue');
        $existing = array_filter(self::TABLES, fn (string $table): bool => $schema->hasTable($table));

        if ($existing !== []) {
            throw new RuntimeException('queue_isolation_schema_already_exists');
        }

        $schema->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $schema->create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $schema->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->secureQueueFile();
    }

    public function down(): void
    {
        $this->assertDedicatedQueueDatabase();

        foreach (self::TABLES as $table) {
            if (! Schema::connection('sqlite_queue')->hasTable($table)) {
                continue;
            }

            if (DB::connection('sqlite_queue')->table($table)->exists()) {
                throw new RuntimeException('queue_isolation_tables_not_empty');
            }
        }

        if ($this->hasQueueSequenceHistory()) {
            throw new RuntimeException('queue_isolation_tables_not_empty');
        }

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::connection('sqlite_queue')->dropIfExists($table);
        }
    }

    private function assertDedicatedQueueDatabase(): void
    {
        if ((config('database.connections.sqlite.driver') ?? null) !== 'sqlite'
            || (config('database.connections.sqlite_queue.driver') ?? null) !== 'sqlite'
            || filled(config('database.connections.sqlite.url'))
            || filled(config('database.connections.sqlite_queue.url'))) {
            throw new RuntimeException('queue_isolation_requires_file_backed_sqlite');
        }

        $primary = config('database.connections.sqlite.database');
        $queue = config('database.connections.sqlite_queue.database');

        if (! is_string($primary) || ! is_string($queue)
            || $primary === ':memory:' || $queue === ':memory:'
            || ! $this->isAbsolutePath($queue)
            || ! is_file($primary) || ! is_file($queue)
            || is_link($queue)) {
            throw new RuntimeException('queue_isolation_requires_existing_private_files');
        }

        $primaryPath = realpath($primary);
        $queuePath = realpath($queue);

        if ($primaryPath === false || $queuePath === false
            || $this->pathsEqual($primaryPath, $queuePath)) {
            throw new RuntimeException('queue_isolation_requires_separate_files');
        }

        $public = realpath(public_path());
        if ($public !== false && $this->isInside($queuePath, $public)) {
            throw new RuntimeException('queue_isolation_file_must_be_outside_public');
        }
    }

    private function secureQueueFile(): void
    {
        $path = realpath((string) config('database.connections.sqlite_queue.database'))
            ?: (string) config('database.connections.sqlite_queue.database');

        if (PHP_OS_FAMILY !== 'Windows'
            && ! chmod($path, 0600)) {
            throw new RuntimeException('queue_isolation_file_permissions_failed');
        }
    }

    private function hasQueueSequenceHistory(): bool
    {
        $connection = DB::connection('sqlite_queue');
        $hasSequence = $connection->selectOne(
            "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'"
        );

        return $hasSequence !== null && $connection->table('sqlite_sequence')
            ->whereIn('name', ['jobs', 'failed_jobs'])
            ->where('seq', '>', 0)
            ->exists();
    }

    private function pathsEqual(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');

        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function isInside(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $directory = rtrim(str_replace('\\', '/', $directory), '/').'/';

        return PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(mb_strtolower($path), mb_strtolower($directory))
            : str_starts_with($path, $directory);
    }
};
