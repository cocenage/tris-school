# Telegram Digest Build Journal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a privacy-safe, append-only build timeline to the existing Telegram outbound operations and expose it read-only in the admin Delivery Center.

**Architecture:** A child event table and `TelegramDigestBuildJournal` attach allow-listed build events to `TelegramOutboundOperation`. `TelegramOutboundMonitor` appends send/response events to that same operation; the five existing command/service paths emit source, filter, format, and queue outcomes. The Filament widget projects a bounded event history without adding write actions.

**Tech Stack:** Laravel 12, Eloquent, SQLite in-memory Pest 4 tests, Filament 5, Livewire 4, Blade, Tailwind CSS 4.

**Spec:** `docs/superpowers/specs/2026-11-01-telegram-digest-build-journal-design.md`

## Global Constraints

- Preserve digest message contents, filters, destinations, schedules, idempotency, and retry behavior.
- Reuse `TelegramOutboundMonitor` and `telegram_outbound_operations`; do not create a second delivery or retry system.
- Keep the event journal on the default connection; keep Telegram analytics reads on the explicit `analytics` connection.
- Never store message text, tokens, Telegram API descriptions, personal comments, or raw source records in diagnostic events.
- Do not edit the weather formatter/source implementation.
- Keep the Filament view read-only and retain its existing admin authorization.
- Do not run migrations against a real database, start workers/schedulers, send Telegram messages, or touch production.

## Review Focus

- Zero rows at a source versus nonzero rows removed by filters: test both outcomes and ensure they remain distinguishable.
- Source exceptions versus post-source calculation/format errors: test both classifications and retain an explicit unclassified result if a boundary cannot safely identify the cause.
- Queue reservation/dispatch versus job execution: test that one operation key and database operation correlate build, enqueue, HTTP attempt, and response.
- Telegram API rejection versus a lost/receipt-less response: test `failed` versus `uncertain` without storing API descriptions or request content.
- Secret/comment redaction and read-only access: test persisted event attributes plus admin/non-admin widget behavior.

---

### Task 1: Add the append-only outbound event journal

**Files:**
- Create: `database/migrations/2026_11_01_000000_create_telegram_outbound_operation_events_table.php`
- Create: `app/Models/TelegramOutboundOperationEvent.php`
- Create: `app/Services/Telegram/TelegramDigestBuildJournal.php`
- Modify: `app/Models/TelegramOutboundOperation.php`
- Test: `tests/Feature/TelegramDigestBuildJournalTest.php`

**Interfaces:**
- `TelegramOutboundOperation::events()` returns the ordered `HasMany` event relation.
- `TelegramDigestBuildJournal::reserve(array $context): void` calls the existing monitor's `expect` for the logical operation and records `build_started`.
- `TelegramDigestBuildJournal::record(array $context, string $stage, string $outcome, array $metrics = []): void` stores only allow-listed codes and scalar bounded metrics; it fails open if journal storage is unavailable.

- [ ] **Step 1: Write the failing journal tests**

Test that an expected outbound operation receives ordered build events; empty and filtered inputs can be represented by distinct stable outcomes; unsupported fields and free-form strings are discarded; absent event storage does not throw or block the caller.

- [ ] **Step 2: Run `php artisan test --compact tests/Feature/TelegramDigestBuildJournalTest.php` and confirm the intended failure**

- [ ] **Step 3: Implement the additive migration, event model/relation, and fail-open journal service**

Use a foreign key to `telegram_outbound_operations.id`, indexed `(operation_id, id)`, and JSON metrics containing only approved count/status keys. Do not copy arbitrary input arrays.

- [ ] **Step 4: Run the focused journal test and confirm it passes**

- [ ] **Step 5: Commit the schema and service**

### Task 2: Record transport attempts and Telegram outcomes in the same timeline

**Files:**
- Modify: `app/Services/Telegram/TelegramOutboundMonitor.php`
- Modify: `tests/Feature/TelegramOutboundMonitorTest.php`

**Interfaces:**
- Reuse `TelegramDigestBuildJournal::record` from Task 1 for digest operation types only. Existing `TelegramOutboundOperation.status`, attempt count, receipt, and error-code behavior remains authoritative and unchanged.

- [ ] **Step 1: Add failing tests for accepted, explicitly rejected, and uncertain responses**

Assert event order (`send_attempt`, then `telegram_response`), operation linkage, and absence of request text, bot token, and Telegram response description from the event rows.

- [ ] **Step 2: Run the focused monitor tests and confirm the new event assertions fail**

- [ ] **Step 3: Append monitor events after the existing operation transitions**

Use only fixed notification types, outcomes, HTTP status/error codes, and attempt counts. Preserve the existing fail-open monitor behavior.

- [ ] **Step 4: Run `php artisan test --compact tests/Feature/TelegramOutboundMonitorTest.php`**

- [ ] **Step 5: Commit monitor integration**

### Task 3: Journal calendar and morning source/filter/build paths

**Files:**
- Modify: `app/Console/Commands/SendTomorrowCalendarEventsNotification.php`
- Modify: `app/Console/Commands/MobilityDigestCommand.php`
- Test: `tests/Feature/TelegramTomorrowSummaryPreviewTest.php` (add an HTTP-faked delivery case)
- Test: `tests/Feature/MobilityDigestCommandTest.php`

**Interfaces:**
- Use `TelegramDigestBuildJournal::reserve` before source processing and `record` with stable source, filter, format, and inline-delivery codes. Continue sending through the current bot and monitor.

- [ ] **Step 1: Add failing calendar and morning tests for no-input, filtered-to-zero, built-message, and source-error events**

Assert the existing operation key, existing filtering counts, and `message_present` only; never assert or persist message text in diagnostics.

- [ ] **Step 2: Run the focused feature tests and confirm the journal assertions fail**

- [ ] **Step 3: Add stage events at existing source and formatting boundaries**

Keep all message building, exclusion, route, and send statements unchanged. Do not modify `MilanWeatherService` or formatter code.

- [ ] **Step 4: Run the focused calendar and mobility command tests**

- [ ] **Step 5: Commit inline digest instrumentation**

### Task 4: Correlate Scheduled Controls build, queue, job, and transport

**Files:**
- Modify: `app/Services/Telegram/TelegramScheduledControlSummaryDeliveryService.php`
- Modify: `app/Jobs/DeliverScheduledControlSummary.php`
- Test: `tests/Feature/TelegramScheduledControlResponsesTest.php`

**Interfaces:**
- Use the same operation key before and after queueing: `scheduled_control_summary:<date>:<sha256(date|chat_id|thread_id)>`.
- The existing `TelegramScheduledControlSummaryDelivery.delivery_key`, persisted summary/text, dispatch connection/queue, and job retry behavior remain unchanged. Keep the delivery row ID as `source_id`.

- [ ] **Step 1: Add failing tests for no deliveries, successful queue dispatch, dispatch failure, and the job's shared operation key to `TelegramScheduledControlResponsesTest.php`**

Verify source-zero is distinct from a filtered/empty formatted result and that no extra queued job or retry is introduced.

- [ ] **Step 2: Run the focused queue/job tests and confirm the correlation assertions fail**

- [ ] **Step 3: Record the build and actual existing `Bus::dispatch` outcome; pass the same monitor context into the queued job**

- [ ] **Step 4: Run the focused Scheduled Controls summary tests**

- [ ] **Step 5: Commit Scheduled Controls instrumentation**

### Task 5: Journal evening intelligence and textile report paths

**Files:**
- Modify: `app/Console/Commands/TelegramEveningIntelligenceSendCommand.php`
- Modify: `app/Console/Commands/TelegramLinenDefectReportCommand.php`
- Modify a builder only if needed to expose a real source/calculation boundary without changing output
- Test: `tests/Feature/TelegramEveningIntelligenceSendCommandTest.php`
- Test: `tests/Feature/TelegramLinenDefectReportTest.php`

**Interfaces:**
- Preserve existing evening operation keys, cache guards, destination selection, formatter calls, and send semantics.
- Journal the standalone textile command only for its real send mode; its dry-run stays untracked. Do not add a schedule.

- [ ] **Step 1: Add failing tests for unavailable/incomplete source, no significant events after filters, calculation/format error, successful build, and standalone send correlation**

Persist only event/source counts, input-completeness codes, and message-present state. Include tests that the combined evening report and standalone linen path do not create a second send.

- [ ] **Step 2: Run the focused evening and linen tests and confirm the new journal assertions fail**

- [ ] **Step 3: Add events around the existing source, inclusion, formatting, and guarded delivery boundaries**

If an exception cannot be classified at the current boundary, record `failure_unclassified`; do not change content or infer a source fault.

- [ ] **Step 4: Run the focused evening and linen tests**

- [ ] **Step 5: Commit evening and textile instrumentation**

### Task 6: Display a bounded read-only event history in the Delivery Center

**Files:**
- Modify: `app/Services/Telegram/TelegramDeliveryCenter.php`
- Modify: `resources/views/filament/widgets/telegram-delivery-center-widget.blade.php`
- Modify: `tests/Feature/TelegramDeliveryCenterTest.php`

**Interfaces:**
- Load ordered events for the existing bounded set of today's digest operations only. Keep `TelegramDeliveryCenterWidget::canView()` and the existing abort guard unchanged.

- [ ] **Step 1: Add failing admin-view tests for event order, outcome labels, and no Telegram/queue writes; assert non-admin access remains denied**

- [ ] **Step 2: Run the focused Livewire/Delivery Center tests and confirm the history assertions fail**

- [ ] **Step 3: Add escaped, read-only event details to each outbound operation**

Keep history bounded and hide arbitrary metadata keys/values not produced by the journal allow-list.

- [ ] **Step 4: Run Delivery Center tests and `npm run build`**

- [ ] **Step 5: Commit the admin history view**

### Task 7: Verify the full scope and prepare the requested PR

**Files:**
- Verify all changed code, tests, migration, and the approved design/plan documents.

- [ ] Re-read the design and this plan; check coverage for all five digest types and each required outcome.
- [ ] Run focused tests for journal, monitor, calendar, mobility, Scheduled Controls, evening/textile, and Delivery Center.
- [ ] Run `php artisan test`, targeted `vendor/bin/pint --test <changed PHP files>`, `npm run build`, and `git diff --check`; record any environment-blocked check precisely.
- [ ] Review the final diff for changed digest output, weather formatter edits, raw text/secrets/comments, access-control changes, new schedule/send/retry behavior, or unrelated work.
- [ ] Commit the documentation and any final corrections; push `codex/telegram-digest-build-journal` and create a PR against `main`.
- [ ] Attach the created PR to this Codex task. Do not merge or deploy.
