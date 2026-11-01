# OI Telegram Update Idempotency Implementation Plan

> **For agentic workers:** Execute task-by-task in this worktree. Keep each regression test red before its implementation and green afterward.

**Goal:** Ensure one OI logical operation per source bot, Telegram chat/message, evaluation kind, and content revision while preserving retries and edits.

**Architecture:** Extend `telegram_operational_observations` with a source-bot dimension and the unique key `(telegram_message_id, source_bot, source_revision_hash, evaluation_kind)`. `telegram_message_id` maps to the existing unique chat/message identity. Pass bot context from the Analytics and Work webhook dispatch points through `ProcessTelegramOperationalMessage` to the existing observer; keep completed observations as the only controller-level skip condition and use the database unique index plus transaction for concurrency.

**Tech Stack:** PHP 8.2+, Laravel 12, Pest 4, SQLite for isolated primary and analytics tests.

**Spec:** `docs/superpowers/specs/2026-11-01-oi-telegram-update-idempotency-design.md`

## Global Constraints

- Analytics observations remain on the separate `analytics` connection.
- Source bots are `analytics`, `work`, or `legacy`; old serialized jobs default to `legacy`.
- The controller guard skips only `completed` observations; queued and failed jobs remain eligible for retry.
- Do not change Telegram HTTP transport, pollers, offsets, queue configuration, user-facing Telegram text, or Scheduled Controls.
- Do not run migrations against an application database, access production, alter queue/failed_jobs data, send Telegram messages, merge, or deploy.
- Use only synthetic fixtures; the concurrency test may create a unique temporary SQLite file and must remove only files it created.

## Review Focus

- Old queued job payloads must deserialize with `sourceBot=legacy`.
- Existing completed observations without bot provenance must not be replayed after rollout.
- Edited text or edit timestamp must produce a new revision, including edits within the same second.
- A failed observation must be retryable and must not overwrite another worker's completed row.
- If both bot sources process the same stored Telegram message, their observations stay separate while the existing chat/message event key maps them to the same root event.
- SQLite `lockForUpdate()` is not treated as the sole concurrency guarantee; the unique index is.
- A duplicate delivery while its first job is still queued may dispatch another job, but must not create a second observation/event.

## File Map

- `database/migrations/2026_11_01_000000_add_source_bot_to_telegram_operational_observations.php` — backfill legacy source and replace the unique index on the analytics connection.
- `app/Models/TelegramOperationalObservation.php` — persist `source_bot`.
- `app/Jobs/ProcessTelegramOperationalMessage.php` — carry source-bot context and preserve it for `unanswered` follow-up jobs.
- `app/Services/Telegram/TelegramOperationalEventObserver.php` — include source bot in identity lookups, preserve legacy compatibility, atomically resolve races, and keep failed work retryable.
- `app/Http/Controllers/TelegramAnalyticsWebhookController.php` and `TelegramWorkWebhookController.php` — pass source bot and skip dispatch only when the matching revision is completed.
- `tests/Feature/TelegramAnalyticsWebhookTest.php`, `TelegramOperationalDedupTest.php`, `TelegramOperationalEventObserverTest.php`, `tests/Support/TelegramOperationalTestDatabase.php`, and `tests/Support/TelegramOperationalConcurrentWorker.php` — replay, edit, failure, and process-race coverage.

## Tasks

### Task 1: Add bot-aware ledger and first Analytics replay tests

- Add a webhook test asserting Analytics dispatches a job tagged `analytics` for a new revision.
- Complete the first job against the synthetic analytics ledger, replay the same update (including via duplicate poll response), and assert there is no second Analytics dispatch and only one completed observation/event.
- Send an `edited_message` with changed text/edit timestamp and assert the new revision dispatches and records separately.
- Test the ledger migration in an isolated SQLite fixture: existing rows default to `legacy`; the exact composite key permits distinct source bots but rejects a duplicate within one source bot/revision/kind.
- Add `source_bot` to the observation model and add the forward/reverse analytics migration replacing `telegram_operational_observations_revision_unique` with a composite key that includes `source_bot`.
- Add a `sourceBot` job property defaulting to `legacy`; pass it through both new webhook dispatch sites and preserve it when dispatching the `unanswered` follow-up.
- Run the new Analytics and schema tests before the implementation to confirm expected failures, then rerun them after the minimum schema/model/job changes.

### Task 2: Add the completed-only Analytics dispatch guard

- Add the failing duplicate-after-completion test before changing the controller.
- Parameterize `hasCompletedCurrentRevision()` by source bot, recognize matching completed legacy rows as a rollout compatibility case, and make Analytics use the same guard as Work after persistence.
- Keep Work's existing guard and supply `work` explicitly; never suppress a failed or merely queued observation.
- Rerun the Analytics and Work webhook tests, including edit preservation and poll replay. Do not change either poll command or transport implementation.

### Task 3: Make observer retries and SQLite races safe

- Add a one-shot interpreter failure test: first execution leaves a failed observation and throws; the next execution completes it with one logical event.
- Add a two-process race test using `tests/Support/TelegramOperationalConcurrentWorker.php`, a fresh test-owned SQLite database, and a ready/go barrier; start identical observer jobs together and assert one completed observation and one logical event. Cleanup only the unique temporary test files.
- Use the unique index as the atomic identity claim inside the analytics transaction. Re-read the winning row after a uniqueness/concurrency error; return idempotently when it completed, and never replace a completed row with `failed`.
- Reuse an existing event rooted at the same Telegram message for a second bot-scoped observation, preserving the event table's existing unique key while keeping both observations traceable.
- Scope current-revision/supersession observation queries to the same source bot, with legacy fallback only for completed historical observations.
- Rerun `vendor/bin/pest tests/Feature/TelegramOperationalDedupTest.php tests/Feature/TelegramOperationalEventObserverTest.php`.

### Task 4: Verify, commit, push, and open PR

- Run the exact Analytics and observer acceptance tests, then `php artisan test --testsuite=Unit`, scoped Pint for changed PHP files, PHP lint for the changed PHP files, and `git diff --check`.
- Confirm only the planned files changed and the branch is based on fetched `origin/main` SHA `4f672ce96723bf3f9b700d9e92cc70ada690ffc9`.
- Commit and push the feature branch; open a PR against `main`. Do not merge or deploy.

## Self-Review

- Spec coverage: first/repeated update and offset replay are Task 1; bot/type/revision identity and edits are Tasks 1–2; concurrent delivery and failure recovery are Task 3; transport, polling, offsets, queue settings, messages, Scheduled Controls, production, merge, and deployment are explicit non-goals.
- Task interfaces: job `sourceBot` defaults to `legacy`; webhook controllers pass `analytics` or `work`; observer methods accept that source context; migration/model fields use `source_bot`.
- Review focus: each listed rollout, edit, queue, failure, and SQLite concurrency condition has a regression assertion in Tasks 1–3.
