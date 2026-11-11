# OI Telegram Update Idempotency — Design

## Intent

Prevent a repeated Telegram update for the same bot, chat, message, processing kind, and message revision from creating another OI logical operation. A real edit remains a new revision. Analytics and work continue to share the existing OI observation ledger.

## Current behavior

- Analytics persists the current message and unconditionally dispatches `ProcessTelegramOperationalMessage` when the observer flag is enabled.
- Work dispatches only when the observer has not completed the message's current revision.
- The observer stores observations on the separate `analytics` connection. Its unique index on message, revision hash, and evaluation kind is the atomic backstop; processing occurs in a transaction and failed observations can be retried.
- The revision hash covers message/chat identity and content/edit metadata, but not the source bot. Existing observations therefore cannot identify which bot created them.

## Chosen approach

Add a `source_bot` dimension (`analytics`, `work`, or `legacy`) to the existing observation ledger and include it in the database unique key. Pass bot context through the two webhook dispatch paths and the job to the observer. Analytics will use the same completed-current-revision guard as Work. The guard checks only `completed`; a queued job is never treated as success, and a failed observation can be retried.

The unique database index remains the concurrency guarantee. The observer will resolve a concurrent conflict by re-reading the winning observation and will not overwrite a concurrently completed row with `failed`. Legacy rows are backfilled as `legacy`; a matching completed legacy revision is reused because its historical bot cannot be inferred. Old queued jobs default to `legacy` for serialization compatibility.

Operational events retain their existing chat/message `event_key`. When two bot-scoped observations refer to the same Telegram message, both observations remain independently auditable and share the event rooted at that message instead of colliding on the event unique key.

The controller guard reduces repeat dispatch after completion. Concurrent deliveries can still enqueue more than one job before completion; the ledger must ensure those jobs produce at most one logical operation. This design does not add a queue claim/outbox state machine or rely on queue uniqueness.

## Goals

- One logical observation per source bot, message, revision, and evaluation kind.
- Analytics duplicate delivery after completion does not dispatch another OI job.
- Edited content creates a new observation.
- SQLite uniqueness and the analytics transaction protect concurrent processing.
- A real processing error remains retryable without turning a queued job into a completed result.

## Non-goals

- Change Telegram HTTP transport, polling, offsets, queue configuration, or `failed_jobs`.
- Change user-facing Telegram text or connect OI to Scheduled Controls.
- Run migrations, process production data, send Telegram messages, merge, or deploy.

## Verification

Add focused tests for first and repeated updates, offset replay, edits, concurrent identical processing, and recovery after an observer failure. Tests use isolated SQLite databases and assert observations/events rather than changing a live queue or analytics database.
