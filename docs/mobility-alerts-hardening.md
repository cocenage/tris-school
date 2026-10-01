# Strike / Mobility Alerts hardening

## Existing inventory and evidence

The existing subsystem is reused; there is no new strike table or replacement product.

| Existing component | Role / state before repair |
| --- | --- |
| `app/Models/MobilityAlert.php`, `2026_05_27_174319_create_mobility_alerts_table.php` | Alert history; unique external_hash, occurrence dates, source/title/type/risk. No structured strike/version state. |
| `app/Models/MobilityAlertMessage.php`, `2026_07_02_042735_create_mobility_alert_messages_table.php` | Sent Telegram receipts; no unique pre-send reservation or queue lease. |
| `app/Services/Mobility/MobilityAlertSyncService.php` | MIT, ATM, Trenord and underground-status ingestion. MIT incorrectly used generic anchor extraction instead of registry rows. |
| `MobilityAlertSyncCommand` (`mobility:sync`) | Called admin delivery only when newly created rows existed; no source dry run or update path. |
| `MobilityAdminAlertsCommand` (`mobility:admin-alerts`) | Direct analytics-bot HTTP sends; receipt dedupe after successful send only; no durable retry. Preserved for unmanaged legacy/non-MIT alerts. |
| `MobilityDigestCommand` (`mobility:digest`) | Existing day-specific worker digest; separate from discovery alerts. |
| `MobilityDeleteMessagesCommand`, `RegisterMobilityTopicsCommand` | Existing manual deletion/topic registration. Unchanged; not executed. |
| `config/services.php` | Existing analytics bot token, mobility worker/admin destination settings. Values were not inspected. |
| `routes/console.php` | Existing `mobility:sync` every 15 minutes with withoutOverlapping; morning digest at 08:00. Existing default database worker every minute. No scheduler edit needed. |
| `app/Filament/Resources/MobilityAlerts/` | Existing list/create/edit/schema/table UI. Unchanged. |
| `OperationalContextBuilder`, `TelegramDigestFormatter` | Existing consumers, no files changed. Explicit cancellations are filtered by the existing shared mobility normalizer. |
| `CalendarEvent`, `CalendarEventsService`, tomorrow notification/calendar views | Existing calendar strike categories; no ingestion replacement or edits. |
| `tests/Feature/MobilityDigestCommandTest.php` | Existing mobility filtering/representation regressions. |
| `tests/Feature/OperationalPreviewCommandTest.php`, `tests/Unit/TelegramDigestFormatterTest.php`, `tests/Feature/TelegramTopicResourceTest.php` | Existing context/formatting/topic coverage. Unchanged. |

Repository evidence does **not** show a discovery date gate or a missing sync schedule. The concrete defects are the MIT anchor parser, creation-only delivery trigger, title/date identity unsuitable for updates, and send-only receipt dedupe without a durable retry reservation. Production configuration and queue execution are unknown; no production reads were made.

## Source and pipeline

Default public source: https://scioperi.mit.gov.it/mit2/public/scioperi

`MOBILITY_STRIKE_SOURCE_URL` overrides the public registry URL. The verified public page on 1 October 2026 lists ATM Milan on 9 October 2026 and structured dates/unions/sector/category/duration/scope/region/province. The official RSS link exists at `/rss`, but the web tool cannot parse its content type and the execution sandbox blocks HTTP sockets. The repair uses the verified registry table, not an invented RSS payload. Actual DOM parser execution against the live page still requires manual validation.

Flow: existing `mobility:sync` -> existing sync service -> `MitStrikeSource` table normalization -> deterministic relevance -> existing MobilityAlert -> version/revision snapshot -> existing MobilityAlertMessage unique reservation -> database/default `DeliverMobilityAlert` -> existing TelegramBotService analytics send -> confirmed receipt.

Structured regions/provinces/operators/sectors are in `config/mobility.php`. Lombardia/Milan passenger transport and national railway/public-transport/general strikes qualify. Distant purely local strikes and unrelated sectors do not. Operator text is a fallback only when geographical metadata is absent. Explicit exclusion of relevant national transport is respected.

New relevant future strikes are queued at discovery; occurrence does not have to be today. Past scheduled rows are skipped using Europe/Rome. Source failure or unrecognized markup changes no strike rows. Invalid items are counted and isolated.

## Identity and delivery

- Official ID, when supplied by the table, takes priority.
- Current public table has no exposed ID: fallback is proclamation date + canonical sorted unions + sector + category. Date/duration/scope amendments retain identity. Conflicting simultaneous rows sharing a fallback identity are rejected rather than selected by source ordering.
- Version excludes reception/fetch timestamps and markup/whitespace/case/union ordering. A separate monotonic revision permits A -> B -> A updates.
- Category/sector/union/proclamation amendments without an official ID cannot reliably be distinguished from another strike; they conservatively become a new identity. Do not claim reliable operator amendment correlation for the ID-less registry.
- Explicit source cancellation/revocation yields one cancellation revision. Missing rows are never inferred to be cancellations. Whether the official source continues exposing withdrawn rows must be checked on the server; disappearance alone cannot provide a trustworthy cancellation feed.
- Reservation key is alert/revision/version/destination, unique before enqueue. Snapshot messages preserve update details.
- Queue insertion and reservation use the same primary database transaction; a different `DB_QUEUE_CONNECTION` is rejected rather than pretending atomicity.
- Database/default jobs retry eight times with 60/120/300/600-second backoff and 30-second timeout. Unsent reservations older than one hour are recoverable on successful sync; reruns within that lease do not enqueue again.
- Missing destination retains the alert for reconciliation when configured. Missing bot token or HTTP failure never sets sent_at. Counts expose destination/token presence without values.
- Delivery row locking makes duplicate/recovered jobs inert after confirmed receipt. As with existing Telegram delivery, an ambiguous remote success followed by timeout/process failure can cause a duplicate on retry: Telegram sendMessage has no application idempotency key. This is at-least-once delivery, not a claim of exactly-once remote delivery.
- Russian alerts distinguish new/update/cancelled, include occurrence/region/category/duration/official URL, and describe possible interruptions rather than certain total stoppage.
- Logs contain counters, reservation IDs and exception classes, not raw source payloads, Telegram destination identifiers or transport URLs/tokens.

## Read-only preview

`php artisan mobility:sync --dry-run`

Fetches MIT only, reads current alert/receipt state and reports new/updated/cancelled/unchanged/already_notified/would_queue and configuration presence. No ledger, receipt, job, cache or Telegram mutation. It does not run the other source synchronizers or legacy sender. Nonzero exit status reports source/item failure.

## Migration and production prerequisites

One minimal, **unapplied** migration:

`database/migrations/2026_10_01_010000_add_strike_delivery_tracking_to_mobility_tables.php`

Adds `mobility_alerts.strike_metadata` and nullable unique `mobility_alert_messages.delivery_key` / `queued_at`; no new tables. The JSON snapshot is needed for structured relevance, cancellation, previous values and version/revision state; receipt columns reserve before send and recover failed delivery.

Before enabling repaired sync in production, an operator must review/apply this exact migration individually; do not run generic migrate because unrelated migrations exist. This task did not run any migration.

Reuse `TELEGRAM_ANALYTICS_BOT_TOKEN` and `TELEGRAM_MOBILITY_ADMIN_TARGETS` (comma-separated chat[:thread] entries); no Scheduled Messages/main-bot token changes. Confirm the analytics bot can post to configured supervisor destinations. Configure `MOBILITY_STRIKE_SOURCE_URL` only if needed, refresh Laravel config through the normal approved deployment process, and confirm the existing scheduler/default database worker actually run. The database queue must use the primary connection. No new infrastructure is required.

Run the read-only preview against the real public source before normal sync. Normal sync writes history and queues real sends: it was not executed here. Initial structured import may discover alerts absent from the old anchor-based ingestion; legacy rows are preserved, not mass-rewritten.

## Verification status

30 focused regression cases added in `tests/Feature/MobilityStrikeSyncTest.php`: discovery before occurrence, repetition/reception changes, Lombardia/national/local relevance, updates/cancellation/duplicate rows, HTTP failure, invalid record isolation, transport/queue failures, delivery receipt/idempotency/recovery, no-write previews, Rome dates, scheduler cadence, fallback identity, ordering, reversion, operator/scope updates under ID, delayed destination configuration and cancelled read-set exclusion.

Executable tests and Pint are **MANUAL VERIFICATION REQUIRED**: PHP is absent from PATH and access to the supplied Herd launcher is denied in the sandbox. No passing test count is claimed. Public page read succeeded through the web tool; raw RSS/socket fetch was blocked. Scoped code review is a static check, not execution.

Manual PowerShell commands (test database only):

```powershell
& 'C:\Users\303wa\.config\herd\bin\php.bat' artisan test --compact tests/Feature/MobilityStrikeSyncTest.php tests/Feature/MobilityDigestCommandTest.php tests/Feature/OperationalPreviewCommandTest.php tests/Unit/TelegramDigestFormatterTest.php
& 'C:\Users\303wa\.config\herd\bin\php.bat' vendor\bin\pint --test app/Console/Commands/MobilityAlertSyncCommand.php app/Console/Commands/MobilityAdminAlertsCommand.php app/Models/MobilityAlert.php app/Models/MobilityAlertMessage.php app/Services/Mobility/MobilityAlertSyncService.php app/Services/Mobility/MitStrikeSource.php app/Services/Mobility/MobilityStrikeSyncService.php app/Jobs/DeliverMobilityAlert.php config/mobility.php database/migrations/2026_10_01_010000_add_strike_delivery_tracking_to_mobility_tables.php tests/Feature/MobilityStrikeSyncTest.php
& 'C:\Users\303wa\.config\herd\bin\php.bat' artisan mobility:sync --dry-run
git diff --check
```

No commit, deployment, migration execution, real send, queue worker, scheduler execution or production-data mutation was performed by this agent. OI, Knowledge Library, Emergency TRIS, Scheduled Messages and Telegram routing files were not edited.
