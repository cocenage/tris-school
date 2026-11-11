# SQLite queue isolation runbook

This change is opt-in. With `DB_QUEUE_DATABASE` unset, the queue, batches, and failed-job history continue to use the same physical SQLite file as before. No production database, queue, worker, scheduler, or Telegram integration is changed by this code release alone.

## Preconditions

- Schedule a maintenance window and identify every producer and consumer: web requests that dispatch jobs, both Telegram pollers, Scheduler invocations, and the queue worker.
- Keep the existing five-minute worker cadence and all poller/scheduler intervals unchanged. This change starts no additional process.
- Use a private writable directory outside `public/`, preferably `storage/framework/`, and configure an absolute path for `DB_QUEUE_DATABASE`.
- Confirm the production PHP SQLite runtime supports attached-database transactions in `DELETE` journal mode. The migration and transfer command deliberately refuse `WAL`; they never change journal mode.
- Ensure enough free disk space exists for the second database and a consistent backup. Do not retry or clear failed jobs as part of this release.

## Cutover

1. Pause incoming writes/dispatches, both Telegram polling cron entries, Scheduler cron, and the queue worker. Wait for active PHP processes to exit. Do not begin while any process can enqueue, reserve, complete, or fail a job.
2. With all writers stopped and no active SQLite journal, copy the primary database to a timestamped path outside the web root. Set `DB_PATH` and `BACKUP_PATH` from the private deployment configuration without printing `.env`; use a backup directory that is not web-served:

   ```sh
   umask 077
   cp -p "$DB_PATH" "$BACKUP_PATH"
   chmod 600 "$BACKUP_PATH"
   php -r '$db = new PDO("sqlite:".$argv[1]); echo $db->query("PRAGMA integrity_check")->fetchColumn(), PHP_EOL;' "$BACKUP_PATH"
   sha256sum "$BACKUP_PATH"
   ```

   Continue only if the result is `ok`. Keep this backup unchanged.
3. Deploy the code without changing scheduled commands. Set `DB_QUEUE_DATABASE` to the new private absolute path (for example, `<private-storage>/sqlite_queue.sqlite`). Leave the existing busy timeout unchanged unless a separately reviewed value is approved.
4. Reload configuration and create the empty file:

   ```sh
   php artisan config:clear
   php artisan config:cache
   php artisan queue:sqlite-isolate --prepare
   ```

   On Unix, the command creates the file with mode `0600`; on Windows it reports that inherited ACLs apply. It refuses to overwrite an existing file and refuses paths under `public/`.
5. Create the queue schema only on the new connection:

   ```sh
   php artisan migrate --database=sqlite_queue --path=database/migrations/queue/2026_11_01_000000_create_sqlite_queue_tables.php --force
   ```

6. Run the default dry-run and review that the primary source contains the expected ready, delayed, reserved, batch, and failed counts; the target is empty; both modes are `delete`; and the schema matches:

   ```sh
   php artisan queue:sqlite-isolate
   ```

7. Only after the operator independently confirms all writers remain stopped, copy every queue row atomically and verify counts:

   ```sh
   php artisan queue:sqlite-isolate --apply --quiesced
   ```

   The command does not dispatch, execute, retry, or delete failed-job history. It preserves row IDs, payloads, attempts, reservations, availability times, batch state, failed-job records, and SQLite ID high-water marks. A failed transaction is rolled back.
8. Verify using a reverse dry-run (do not add `--apply`) and read-only queue counts. Keep the primary backup and the new queue file. Resume the unchanged application traffic, polling, Scheduler, and worker only after the counts reconcile.
9. Observe normal logs and the existing worker/poller health for the first scheduled cycle. Do not manually run `queue:work`, pollers, retries, or Telegram sends as a smoke test.

## Rollback

1. Pause web dispatches, pollers, Scheduler, and queue worker again; wait for active processes to exit.
2. Keep `DB_QUEUE_DATABASE` configured to the dedicated file and preview the reverse direction:

   ```sh
   php artisan queue:sqlite-isolate --reverse
   ```

3. If the dedicated file is the sole current queue source and the primary queue destination is empty, reverse the current queue state:

   ```sh
   php artisan queue:sqlite-isolate --reverse --apply --quiesced
   ```

   The command refuses a non-empty destination or a schema mismatch. It moves pending, delayed, reserved, batch, and failed rows; it does not retry them.
4. Restore the previous application code/configuration, remove the opt-in `DB_QUEUE_DATABASE` setting from the private environment file, clear/rebuild Laravel's configuration cache, and resume the original processes.
5. Keep both the backup and the dedicated queue SQLite file, including its migration history. Do not run the migration `down` or delete either database as part of rollback.

## Load and timing notes

The cutover adds one private SQLite file but no worker, polling loop, Scheduler event, or retry. Queue reservations, job execution, and retry timing remain governed by the existing worker cron and job settings. The one-time row copy takes a write lock on both SQLite files and its duration depends on queue payload/history size; perform it only during the approved quiet window. Splitting files reduces contention between primary application writes and queue writes, but does not remove contention among queue writers or prove a particular REG.RU CPU percentage. Measure host CPU and I/O after a controlled activation before declaring the limit impact acceptable.
