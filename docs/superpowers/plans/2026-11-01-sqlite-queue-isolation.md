# SQLite Queue Isolation Implementation Plan

> **For agentic workers:** Execute this bounded plan in `codex/sqlite-queue-isolation` only. Do not run queue workers, migrations, or transfer commands against production.

**Goal:** Prepare Laravel's database queue to use a private SQLite file distinct from the primary application database, with a guarded reversible transfer for existing queue tables.

**Architecture:** Keep the current same-file behavior unless `DB_QUEUE_DATABASE` is explicitly configured. When configured, route jobs, batches, and failed-job history to `sqlite_queue`, defer dispatch until primary transactions commit, create queue tables through a connection-scoped migration, and move rows through an explicit dry-run/apply/reverse command. The transfer must refuse WAL, same-file paths, schema mismatches, and non-empty destinations; production cutover remains manual.

**Tech Stack:** Laravel 12, PHP 8.4, SQLite, Pest 4.

**Spec:** User request `TRIS Academy — P1: SQLite Queue Isolation` (2026-11-01).

## Global Constraints

- Production data, config, queues, schedulers, and Telegram remain unchanged.
- Do not use WAL or add dependencies.
- Preserve ready, delayed, reserved, batched, and failed queue rows byte-for-byte where practical.
- Do not run mass retries or delete failed-job history.
- Preserve current behavior when queue isolation is not explicitly activated.

## Review Focus

- Queue settings must not silently point existing deployments at an empty file.
- Dispatches must not become visible to a worker before a primary-database transaction commits.
- Cross-file transfer must be atomic under the required SQLite journal mode and fail closed otherwise.
- The destination must be empty and schema-compatible before any source row is removed.
- Reversal must move current pending/history rows back without restoring an old primary database over newer application data.

---

### Task 1: Explicit queue database configuration

**Files:**
- Modify: `config/database.php`
- Modify: `config/queue.php`
- Modify: `.env.example`
- Test: `tests/Feature/SqliteQueueIsolationTest.php`

- [x] Verify default queue paths still resolve to the primary SQLite file.
- [x] Verify an explicit `DB_QUEUE_DATABASE` isolates jobs, batches, and failed jobs and enables after-commit dispatch.
- [x] Add only the environment example and configuration needed for that behavior.

### Task 2: Queue schema and guarded row transfer

**Files:**
- Create: `database/migrations/queue/2026_11_01_000000_create_sqlite_queue_tables.php`
- Create: `app/Services/Queue/SqliteQueueIsolationService.php`
- Create: `app/Console/Commands/IsolateSqliteQueueCommand.php`
- Test: `tests/Feature/SqliteQueueIsolationTest.php`

- [x] Add failing tests for queue schema creation, dry-run, transfer, refusal cases, and reversal.
- [x] Create `jobs`, `job_batches`, and `failed_jobs` on `sqlite_queue` without setting journal mode.
- [x] Implement a default dry-run and explicit `--apply` / `--reverse` transfer with atomic attached-database transaction and count verification.
- [x] Preserve queue row ids, attempts, reservations, timestamps, payloads, batch state, failed-job UUIDs, and failed-job history.

### Task 3: File-backed concurrency and release procedure

**Files:**
- Modify: `tests/Feature/SqliteQueueIsolationTest.php`
- Create: `docs/operations/sqlite-queue-isolation.md`

- [x] Exercise overlapping primary-database writers and queue dispatch/reservation on separate file-backed SQLite databases.
- [x] Document consistent backups, producer/worker quiescence, permissions, migration, verification, rollback, and CPU caveats.

### Task 4: Verification and release artifact

- [x] Run focused Pest tests using disposable file-backed SQLite databases.
- [x] Run PHP lint and Pint. `git diff --check` and commit/push are performed after final staging.
- [x] Commit and push only the queue-isolation change; do not merge or deploy.

## Runtime verification boundary

Local tests used PHP 8.4.7 with SQLite 3.46.0. No local SQLite 3.26.0 runtime or CLI was available, so the exact REG.RU runtime compatibility check remains a release prerequisite. Production was not accessed or changed.
