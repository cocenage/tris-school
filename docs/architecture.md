# TRIS Academy architecture

This is a compact map for task-oriented development. Source code remains authoritative.

## Runtime and entry points

TRIS is one Laravel 12 application with three Filament 5 panels (`Admin`, `Education`, `Finance`) plus authenticated Livewire/Volt staff pages. HTTP routes live in `routes/web.php` and `routes/api.php`; scheduled commands are registered in `routes/console.php`.

The main layers are:

1. Controllers and Livewire/Filament components receive user or webhook input.
2. Services hold operational and integration logic.
3. Jobs isolate queued work.
4. Eloquent models persist primary or analytics data.
5. Artisan commands expose previews, bounded maintenance, and operational processing.

## Data stores

The default connection is the application database. Users, apartments, controls, requests, tasks, calendars, mobility state, and TRIS Mare state use it.

Telegram analytics is intentionally separate:

- connection: `analytics` in `config/database.php`;
- models: `TelegramChat`, `TelegramTopic`, `TelegramUser`, `TelegramMessage`, attachments, assistant requests, observations, operational events, and evidence;
- migrations that target analytics call `Schema::connection('analytics')` explicitly;
- an apartment remains a primary-database model. Analytics rows store its numeric identifier but cannot enforce a cross-database foreign key.

Do not infer that a migration in `database/migrations` targets the primary database; inspect its connection calls.

## Telegram flow

The existing flow is reused end to end:

`Telegram webhook → existing chat/topic/user/message persistence → queued processing → assistant or operational services`

- `TelegramAnalyticsWebhookController` persists the analytics feed directly.
- `TelegramWorkWebhookController` uses `TelegramUpdateIngestService` for the work feed and keeps callback authorization in the application.
- `ProcessTelegramOperationalMessage` calls `TelegramOperationalEventObserver`; unanswered evaluation is a delayed mode of the same job.
- Operational events, observations, lifecycle transitions, and evidence remain on `analytics`.
- `TelegramOperationalReplayCommand` reuses the observer for bounded historical or current-day catch-up. It writes the ledger and is not a read-only preview.
- `TelegramEveningIntelligencePreviewCommand` reads the ledger and formats a preview.
- `TelegramEveningIntelligenceSendCommand` is delivery-capable, feature-flagged, and must never be treated as a preview unless `--dry-run` is explicitly used and verified.

`TelegramForumDigestBuilder` is an older read-only forum-level aggregate. It is not a replacement for the operational event ledger.

### Telegram time contract

- Telegram `date` and `edit_date` values are Unix timestamps and are treated as absolute UTC instants at ingestion.
- Analytics `telegram_messages.sent_at` / `edited_at`, OI occurrence fields (`first_observed_at`, `last_observed_at`, `resolved_at`, and evidence `occurred_at`), and OI `unanswered_due_at` are stored in SQLite as timezone-naive UTC values. `TelegramUtcDateTime` converts these values to the configured application timezone when Eloquent reads them and converts assigned date-times back to UTC before storage.
- Rome reporting windows are constructed from local calendar boundaries in `Europe/Rome`, then those instants are converted to UTC for SQL comparisons. The daily Evening/Linen window is half-open: `[00:00, 20:30)` Rome time; its elapsed duration naturally changes at daylight-saving transitions.
- Laravel audit timestamps such as `created_at` and `updated_at`, and OI processing metadata such as `processed_at`, retain their existing application-timezone behavior. They are not source occurrence timestamps.
- This contract does not rewrite existing rows. Historical Telegram/OI source timestamps written by the current Unix-timestamp ingestion path are already UTC wall values; manually entered or older imported values require provenance checks before any separate data correction.

## Operational context

`OperationalContextBuilder` is a read-only aggregator over existing staff, calendar, request, task, control, TRIS Mare, mobility, and Telegram sources. `operational:preview` exposes it without AI or Telegram delivery. Source failures are represented as data-quality entries rather than silently converted into writes.

## Queues and schedules

The normal queue driver is database-backed; Pest sets it to `sync`. Queue jobs include Telegram operational processing, Telegram instruction replies, request result notifications, and Telegram notifications.

The scheduler currently covers calendar notices, task deadlines, mobility sync/digest, TRIS Mare sync, operational catch-up, and evening intelligence. Schedule changes are production behavior and require explicit scope and approval.

## Tests

Pest has `Unit` and `Feature` suites. The primary test database is SQLite `:memory:`. Telegram tests that touch analytics must replace the analytics database with `:memory:`; the shared operational fixture does this in `tests/Support/TelegramOperationalTestDatabase.php`.

There is no repository CI workflow at the time of this audit. Local verification is therefore the authoritative automated gate.
