<?php

namespace App\Services\Queue;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class SqliteQueueIsolationService
{
    private const TABLES = ['jobs', 'job_batches', 'failed_jobs'];

    public function prepare(): array
    {
        $this->assertQueueConfiguration();

        $primaryPath = $this->existingFilePath('sqlite');
        $queuePath = $this->configuredQueuePath();
        $this->assertDifferentFiles($primaryPath, $queuePath);
        $this->assertOutsidePublic($queuePath);

        if (! is_dir(dirname($queuePath)) || ! is_writable(dirname($queuePath))) {
            throw new \RuntimeException('queue_isolation_directory_not_writable');
        }

        if (file_exists($queuePath) || is_link($queuePath)) {
            throw new \RuntimeException('queue_isolation_file_already_exists');
        }

        $previousUmask = PHP_OS_FAMILY === 'Windows' ? null : umask(0077);

        try {
            $handle = @fopen($queuePath, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException('queue_isolation_file_create_failed');
            }

            fclose($handle);
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! chmod($queuePath, 0600)) {
            throw new \RuntimeException('queue_isolation_file_permissions_failed');
        }

        return [
            'prepared' => true,
            'outside_public' => true,
            'permissions' => PHP_OS_FAMILY === 'Windows' ? 'inherited_acl' : '0600',
            'path' => $queuePath,
        ];
    }

    public function preview(bool $reverse = false): array
    {
        $context = $this->context($reverse);
        $pdo = $this->sourcePdo($context['source_connection']);
        $this->attachTarget($pdo, $context['target_path']);

        try {
            return $this->inspectAttached($pdo);
        } finally {
            $this->detachTarget($pdo);
        }
    }

    public function transfer(bool $reverse = false): array
    {
        $context = $this->context($reverse);
        $pdo = $this->sourcePdo($context['source_connection']);
        $this->attachTarget($pdo, $context['target_path']);
        $transactionStarted = false;

        try {
            $before = $this->inspectAttached($pdo);
            $this->assertTransferSafe($before);

            if ($pdo->inTransaction()) {
                throw new \RuntimeException('queue_isolation_connection_already_in_transaction');
            }

            $pdo->exec('BEGIN IMMEDIATE');
            $transactionStarted = true;

            $sourceSequences = $this->sequences($pdo, 'main');
            $targetSequences = $this->sequences($pdo, 'queue_target');

            // Lock the destination writer without leaving a visible queue row.
            $pdo->exec("INSERT INTO queue_target.jobs (id, queue, payload, attempts, available_at, created_at) VALUES (0, '__tris_queue_isolation_lock__', '', 0, 0, 0)");
            $pdo->exec("DELETE FROM queue_target.jobs WHERE id = 0 AND queue = '__tris_queue_isolation_lock__'");

            $locked = $this->inspectAttached($pdo);
            $this->assertTransferSafe($locked);

            foreach (self::TABLES as $table) {
                $pdo->exec("INSERT INTO queue_target.{$table} SELECT * FROM main.{$table}");
            }

            $this->restoreSequences($pdo, $sourceSequences, $targetSequences);

            foreach (self::TABLES as $table) {
                $pdo->exec("DELETE FROM main.{$table}");
            }

            $this->verifyTransfer($pdo, $locked);
            $pdo->exec('COMMIT');
            $transactionStarted = false;

            return $locked;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Preserve the original transfer failure.
                }
            }

            if ($exception instanceof \RuntimeException
                && str_starts_with($exception->getMessage(), 'queue_isolation_')) {
                throw $exception;
            }

            throw new \RuntimeException('queue_isolation_transfer_failed', previous: $exception);
        } finally {
            $this->detachTarget($pdo);
        }
    }

    private function context(bool $reverse): array
    {
        $this->assertQueueConfiguration();

        $sourceConnection = $reverse ? 'sqlite_queue' : 'sqlite';
        $targetConnection = $reverse ? 'sqlite' : 'sqlite_queue';
        $sourcePath = $this->existingFilePath($sourceConnection);
        $targetPath = $this->existingFilePath($targetConnection);
        $this->assertDifferentFiles($sourcePath, $targetPath);
        $this->assertOutsidePublic($targetPath);

        return [
            'source_connection' => $sourceConnection,
            'target_connection' => $targetConnection,
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
        ];
    }

    private function assertQueueConfiguration(): void
    {
        if (config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite_queue.driver') !== 'sqlite'
            || filled(config('database.connections.sqlite.url'))
            || filled(config('database.connections.sqlite_queue.url'))
            || config('queue.default') !== 'database'
            || config('queue.connections.database.driver') !== 'database'
            || config('queue.connections.database.connection') !== 'sqlite_queue'
            || config('queue.connections.database.table') !== 'jobs'
            || config('queue.batching.database') !== 'sqlite_queue'
            || config('queue.batching.table') !== 'job_batches'
            || config('queue.failed.database') !== 'sqlite_queue'
            || config('queue.failed.table') !== 'failed_jobs') {
            throw new \RuntimeException('queue_isolation_configuration_not_supported');
        }
    }

    private function existingFilePath(string $connection): string
    {
        $path = config("database.connections.{$connection}.database");

        if (! is_string($path) || $path === '' || $path === ':memory:'
            || str_contains($path, 'mode=memory') || str_starts_with($path, 'file:')
            || ! is_file($path) || is_link($path)) {
            throw new \RuntimeException('queue_isolation_requires_existing_private_files');
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            throw new \RuntimeException('queue_isolation_database_path_invalid');
        }

        return $resolved;
    }

    private function configuredQueuePath(): string
    {
        $path = config('database.connections.sqlite_queue.database');

        if (! is_string($path) || trim($path) === '' || $path === ':memory:'
            || str_contains($path, 'mode=memory') || str_starts_with($path, 'file:')) {
            throw new \RuntimeException('queue_isolation_requires_file_backed_sqlite');
        }

        if (! $this->isAbsolutePath($path)) {
            throw new \RuntimeException('queue_isolation_requires_absolute_path');
        }

        $parent = realpath(dirname($path));
        if ($parent === false) {
            throw new \RuntimeException('queue_isolation_directory_not_found');
        }

        return $parent.DIRECTORY_SEPARATOR.basename($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function assertDifferentFiles(string $left, string $right): void
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        $same = PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;

        if ($same) {
            throw new \RuntimeException('queue_isolation_requires_separate_files');
        }
    }

    private function assertOutsidePublic(string $path): void
    {
        $public = realpath(public_path());
        if ($public === false) {
            throw new \RuntimeException('queue_isolation_public_path_unavailable');
        }

        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $public = rtrim(str_replace('\\', '/', $public), '/').'/';
        $inside = PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(mb_strtolower($path), mb_strtolower($public))
            : str_starts_with($path, $public);

        if ($inside) {
            throw new \RuntimeException('queue_isolation_file_must_be_outside_public');
        }
    }

    private function sourcePdo(string $connection): PDO
    {
        $pdo = DB::connection($connection)->getPdo();
        $busyTimeout = (int) config("database.connections.{$connection}.busy_timeout", 5000);
        $pdo->exec('PRAGMA busy_timeout = '.max(0, $busyTimeout));

        return $pdo;
    }

    private function attachTarget(PDO $pdo, string $path): void
    {
        try {
            $statement = $pdo->prepare('ATTACH DATABASE ? AS queue_target');
            $statement->execute([$path]);
        } catch (Throwable $exception) {
            throw new \RuntimeException('queue_isolation_attach_failed', previous: $exception);
        }
    }

    private function detachTarget(PDO $pdo): void
    {
        try {
            $pdo->exec('DETACH DATABASE queue_target');
        } catch (Throwable) {
            // SQLite detaches automatically when the connection is closed.
        }
    }

    private function inspectAttached(PDO $pdo): array
    {
        $schemas = [];
        $schemaMatches = true;
        foreach (self::TABLES as $table) {
            $sourceSchema = $this->schemaObjects($pdo, 'main', $table);
            $targetSchema = $this->schemaObjects($pdo, 'queue_target', $table);
            $schemas[$table] = [$sourceSchema, $targetSchema];

            if ($sourceSchema === [] || $targetSchema === [] || $sourceSchema !== $targetSchema) {
                $schemaMatches = false;
            }
        }

        $sourceMode = strtolower((string) $pdo->query('PRAGMA main.journal_mode')->fetchColumn());
        $targetMode = strtolower((string) $pdo->query('PRAGMA queue_target.journal_mode')->fetchColumn());

        foreach ($schemas as [$sourceSchema, $targetSchema]) {
            if ($sourceSchema === [] || $targetSchema === []) {
                return [
                    'safe_to_transfer' => false,
                    'blocking_reason' => 'queue_isolation_schema_missing',
                    'source_journal_mode' => $sourceMode,
                    'target_journal_mode' => $targetMode,
                    'source_counts' => null,
                    'target_counts' => null,
                ];
            }
        }

        $sourceCounts = $this->counts($pdo, 'main');
        $targetCounts = $this->counts($pdo, 'queue_target');

        $blockingReason = null;
        if (! $schemaMatches) {
            $blockingReason = 'queue_isolation_schema_mismatch';
        } elseif ($sourceMode !== 'delete' || $targetMode !== 'delete') {
            $blockingReason = 'queue_isolation_journal_mode_not_delete';
        } elseif (array_sum($targetCounts) !== 0) {
            $blockingReason = 'queue_isolation_target_not_empty';
        }

        return [
            'safe_to_transfer' => $blockingReason === null,
            'blocking_reason' => $blockingReason,
            'source_journal_mode' => $sourceMode,
            'target_journal_mode' => $targetMode,
            'source_counts' => $sourceCounts,
            'target_counts' => $targetCounts,
        ];
    }

    private function schemaObjects(PDO $pdo, string $schema, string $table): array
    {
        $statement = $pdo->query(
            "SELECT type, name, sql FROM {$schema}.sqlite_master WHERE tbl_name = '{$table}' AND type IN ('table', 'index', 'trigger') ORDER BY type, name"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function counts(PDO $pdo, string $schema): array
    {
        $now = now()->timestamp;
        $statement = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN reserved_at IS NULL AND available_at <= :now THEN 1 ELSE 0 END), 0) AS ready,
                COALESCE(SUM(CASE WHEN reserved_at IS NULL AND available_at > :now THEN 1 ELSE 0 END), 0) AS delayed,
                COALESCE(SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS reserved
             FROM {$schema}.jobs"
        );
        $statement->execute(['now' => $now]);
        $jobs = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'ready' => (int) $jobs['ready'],
            'delayed' => (int) $jobs['delayed'],
            'reserved' => (int) $jobs['reserved'],
            'job_batches' => $this->countTable($pdo, $schema, 'job_batches'),
            'failed_jobs' => $this->countTable($pdo, $schema, 'failed_jobs'),
        ];
    }

    private function countTable(PDO $pdo, string $schema, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM {$schema}.{$table}")->fetchColumn();
    }

    private function assertTransferSafe(array $report): void
    {
        if (! $report['safe_to_transfer']) {
            throw new \RuntimeException($report['blocking_reason'] ?? 'queue_isolation_transfer_blocked');
        }
    }

    private function sequences(PDO $pdo, string $schema): array
    {
        $exists = $pdo->query(
            "SELECT 1 FROM {$schema}.sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'"
        )->fetchColumn();

        if ($exists === false) {
            return [];
        }

        $statement = $pdo->query("SELECT name, seq FROM {$schema}.sqlite_sequence WHERE name IN ('jobs', 'failed_jobs')");
        $sequences = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sequences[$row['name']] = (int) $row['seq'];
        }

        return $sequences;
    }

    private function restoreSequences(PDO $pdo, array $source, array $target): void
    {
        foreach (['jobs', 'failed_jobs'] as $table) {
            $maximumId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM queue_target.{$table}")->fetchColumn();
            $sequence = max($source[$table] ?? 0, $target[$table] ?? 0, $maximumId);
            $exists = $pdo->prepare('SELECT 1 FROM queue_target.sqlite_sequence WHERE name = ?');
            $exists->execute([$table]);

            if ($exists->fetchColumn() !== false) {
                $update = $pdo->prepare('UPDATE queue_target.sqlite_sequence SET seq = ? WHERE name = ?');
                $update->execute([$sequence, $table]);
            } else {
                $insert = $pdo->prepare('INSERT INTO queue_target.sqlite_sequence (name, seq) VALUES (?, ?)');
                $insert->execute([$table, $sequence]);
            }
        }
    }

    private function verifyTransfer(PDO $pdo, array $before): void
    {
        $source = $this->counts($pdo, 'main');
        $target = $this->counts($pdo, 'queue_target');
        $expectedJobs = $before['source_counts']['ready']
            + $before['source_counts']['delayed']
            + $before['source_counts']['reserved'];

        if (array_sum($source) !== 0
            || $expectedJobs !== $target['ready'] + $target['delayed'] + $target['reserved']
            || $target['job_batches'] !== $before['source_counts']['job_batches']
            || $target['failed_jobs'] !== $before['source_counts']['failed_jobs']) {
            throw new \RuntimeException('queue_isolation_transfer_count_mismatch');
        }
    }
}
