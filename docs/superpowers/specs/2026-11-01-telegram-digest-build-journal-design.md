# Telegram Digest Build Journal — Design

## Intent

Give administrators a read-only, chronological account of how the five automated Telegram digest paths were built and delivered. Today `TelegramOutboundMonitor` records the outbound HTTP operation, but most source, filtering, calculation, formatting, and queue outcomes happen before that record is attempted.

## Goals

- Record bounded stage events for the calendar summary, morning weather and transport digest, Scheduled Controls summary, evening Operational Intelligence summary, and textile-defect report.
- Distinguish missing input, no significant events after filters, source failure, calculation failure, no generated message, successful build, queued work, Telegram acceptance, Telegram rejection, and uncertain delivery.
- Link every build to the existing logical operation in `telegram_outbound_operations` and keep using `TelegramOutboundMonitor` for network attempts and Telegram results.
- Add an admin-only read-only history view to the existing Telegram Delivery Center.
- Store only stable event codes, allow-listed source/stage names, and bounded aggregate counts. Never copy message text, Telegram token values, Telegram API descriptions, or staff comments into diagnostics.

## Non-goals and anti-goals

- Do not create a second Telegram delivery or retry system.
- Do not change digest text, selection/filter rules, schedules, destinations, queue policy, idempotency, or delivery behavior.
- Do not rewrite the weather formatter or weather source implementation.
- Do not add another scheduled textile send. The existing standalone linen command remains manually invokable; its report is already included in the evening summary.
- Do not modify production data, run a production migration, or send Telegram messages.

## Approaches

1. Add status and JSON diagnostics fields to each outbound-operation row. This is the smallest schema change, but repeated builds overwrite earlier stages and cannot provide the requested history.
2. Add an append-only child event table keyed by `telegram_outbound_operations.id`. This keeps the existing outbound operation as the sole logical delivery record while preserving multiple build stages and repeated attempts. **Chosen.**
3. Add an independent digest-run table. This can hold a timeline, but duplicates operation identity and risks creating the parallel system the request excludes.

## Proposed design

Add `telegram_outbound_operation_events` with a foreign key to the existing outbound operation, a stable `stage` code, stable `outcome` code, optional JSON `metrics`, and timestamps. Events are append-only. The existing row remains authoritative for operation identity and current delivery status; `TelegramOutboundMonitor` remains authoritative for attempts, API rejection, acceptance receipts, and uncertain transport outcomes. The monitor appends corresponding timeline events without recording request/response bodies.

Create/reserve the logical outbound operation before digest processing so even a source failure or empty result has a traceable operation ID. A small journal service writes source, filtering, calculation, formatting, and queue events using a fixed code vocabulary and an allow-list of numeric metrics. Journal failures must be fail-open and must never block existing delivery behavior.

For inline paths, record that delivery is inline; for the Scheduled Controls path, record the existing queue dispatch outcome and correlate the existing queued job's send to the same logical operation. Dry-run/preview commands remain non-delivery operations and do not create automated-delivery journal entries.

Scheduled Controls reserves its journal operation before building, using a key derived from the summary date and a hash of its configured destination. The queue job receives that same key; the existing `TelegramScheduledControlSummaryDelivery` row and its idempotency key remain unchanged. The delivery row ID remains the source reference. Older outbound rows keyed by delivery ID remain historical records.

The Delivery Center will query a bounded set of today's digest operations and eagerly load their event timelines. Its existing administrator authorization remains in force. The view adds no controls or mutation actions.

### Sources and processing stages

| Digest | Existing sources and filters to describe with codes/counts | Existing delivery path |
|---|---|---|
| Calendar for tomorrow | `CalendarEventsService`, `CalendarSummaryService`; event expansion and current duplicate/non-working-event removal | Inline `calendar:notify-tomorrow` send via existing monitor |
| Morning weather and transport | Mobility alert rows, `MobilityStrikesSummaryBuilder`, `MilanWeatherService`; current relevance, deduplication, importance, and strike-overlap filters | Inline per-district send via existing monitor |
| Scheduled Controls totals | `TelegramScheduledControlSummaryBuilder` and formatter over existing scheduled deliveries/responses; retain current due/empty/duplicate guards | Existing `TelegramScheduledControlSummaryDeliveryService` queue and `DeliverScheduledControlSummary` job |
| Evening Operational Intelligence | Analytics OI ledger, district route inputs, `TelegramEveningIntelligenceBuilder`, textile report builder, existing `TelegramDigestFormatter`; current revision, date-window, relevance, and inclusion filters | Existing feature-flagged/idempotent send via existing monitor |
| Textile-defect report | Analytics OI ledger and `TelegramLinenDefectReportBuilder`/formatter; existing explicit-defect and quantity rules | Included in evening summary; standalone command is separately journaled only when invoked for delivery |

Counts and state codes must preserve the distinction between zero source rows and rows removed by existing filters. Source acquisition and post-source filtering/formatting receive separate event boundaries. A failure at an acquisition boundary is `source_error`; a failure after successful acquisition is `calculation_error`. If an existing service combines those stages and the precise origin cannot be identified without changing output behavior, record `failure_unclassified` rather than guessing.

The delivery-capable standalone textile command is in scope when it is invoked to send. Its dry-run remains an untracked preview. This closes the manually invoked outbound path without adding a schedule or another send.

## Constraints and implementation choices

- Keep the journal on the primary/default connection with `telegram_outbound_operations`; analytics input reads stay on the existing `analytics` connection.
- Preserve current primary and analytics test isolation. Tests must use the in-memory fixtures configured by the repository.
- Use stable stage/outcome codes and bounded numeric metrics. Do not include unbounded exception messages or source records.
- Dashboard history queries are bounded and read-only. No new Telegram calls, queue writes, or analytics writes occur in the widget.
- If an existing catch boundary cannot reliably distinguish a source exception from calculation logic, record `failure_unclassified` rather than changing builder behavior to infer a cause.

## Acceptance

- Feature tests cover the event schema and redaction boundary, all outcome distinctions that the instrumented paths can actually observe, operation-key correlation, existing send response integration, and queue-path correlation.
- Delivery Center tests prove event visibility for administrators and continued read-only behavior / access denial for non-admins.
- Existing digest output and formatter tests stay unchanged and pass.
- Targeted Pint, `git diff --check`, and the repository-appropriate build check pass. Any missing test/browser/build capability is reported as a remaining gap.

## Builder calls fixed by this design

- Implement a small fixed vocabulary of stage/outcome codes; include only numeric counters available at existing boundaries.
- Preserve existing keys for calendar, morning, and evening paths. Use date plus a destination hash for the Scheduled Controls monitor operation so the build and worker share one pre-build identity; do not alter the existing queued-delivery idempotency key or retry policy.
- Journal the existing queue dispatch result after `Bus::dispatch`; do not introduce a new queue or retry path.
- Track actual standalone textile sends, but not its dry-run output.
