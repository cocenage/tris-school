# Quickstart: Validate the Operational Event Ledger

This guide is for a development/test analytics database. It does not authorize running migrations, replay, workers, or external writes against production.

## Prerequisites

- Project dependencies are installed.
- Test configuration uses isolated main and analytics stores.
- Queue and HTTP transports are faked in automated acceptance tests.
- The feature migration has been applied only in the intended development/test environment.

## 1. Run the focused acceptance suite

```powershell
php artisan test tests/Unit/TelegramOperationalInterpreterTest.php
php artisan test tests/Feature/TelegramOperationalEventObserverTest.php
php artisan test tests/Feature/TelegramOperationalReplayCommandTest.php
php artisan test tests/Feature/TelegramOperationalEventsCommandTest.php
php artisan test tests/Feature/TelegramWorkWebhookTest.php
php artisan test tests/Feature/TelegramAnalyticsWebhookTest.php
```

Expected:

- ordinary, empty, bot, service, private, and disallowed messages create no event;
- meaningful messages cover every Spec 001 event type;
- one scenario progresses through create → detail/action → resolved → reopened;
- ambiguous resolution remains uncertain and does not resolve;
- duplicate delivery/retry/replay creates no duplicate rows or transitions;
- an edit is reconsidered and preserves correction history;
- the four-hour boundary considers messages at the deadline before maturing an unanswered question;
- HTTP records remain empty for silent observation/replay scenarios.

The reviewed acceptance corpus must meet SC-004 and SC-005 before rollout; deterministic rules are not assumed to meet those targets merely because synthetic unit cases pass.

### Recorded one-day checkpoint

The isolated, manually labelled `2026-06-17` acceptance fixture contains 20 eligible messages: 16 ordinary messages and 4 meaningful situations (problem, risk, delay, and quality issue). The automated replay assertion verifies:

- SC-004: 16/20 messages (80%) produce no event, and 16/16 reviewed ordinary messages (100%) produce no event;
- SC-005 at the initial-event checkpoint: 4/4 meaningful situations (100%) produce the expected event type, with one source message per separate situation and no merge;
- replay transport activity: zero HTTP/Telegram calls.

This is an isolated reviewed fixture, not authorization to run against or enable observation for production data. Live observation remains disabled by default.

## 2. Verify replay bounds before writes

Against a fixture date, test invalid selectors:

```powershell
php artisan telegram:operational-replay
php artisan telegram:operational-replay --from=2026-06-01 --to=2026-06-08
```

Expected: both commands fail validation before examining messages, and ledger counts do not change.

## 3. Replay one reviewed date

```powershell
php artisan telegram:operational-replay --date=2026-06-17 --json
```

Expected JSON:

- reports the exact date and timezone;
- reports all required outcome counts and affected stable event keys;
- contains `telegram_actions: 0`;
- contains no raw payload or full message text.

Run the same command again. Expected: event, observation, and evidence counts are unchanged; results report idempotent reuse rather than duplicates.

## 4. Inspect evidence and decisions

```powershell
php artisan telegram:operational-events --date=2026-06-17
php artisan telegram:operational-events --event="telegram:-100123:456" --json
php artisan telegram:operational-events --message=123 --json
```

Expected:

- event output shows current lifecycle, confidence/uncertainty, ordered transitions, and source identifiers;
- message output can explain a no-event or failed decision;
- raw payload and full message body are absent;
- inspection performs no writes.

## 5. Verify live/replay parity

Use the same chronological fixture in two isolated analytics stores:

1. Feed it through both supported live persistence paths with queued jobs executed.
2. Preload the source messages and replay the same logical period.
3. Compare stable event keys, statuses, type sets, confidence, transition sequences, and evidence source identities.

Expected: projections are equivalent, including the four-hour unanswered evaluation, and all paths produce zero Telegram actions.

### Verified replay contract (2026-09-16)

The isolated acceptance suite verified invalid-boundary rejection before ledger writes, inclusive seven-day processing, equal-timestamp response handling, range-end pending deadlines, per-message failure continuation, repeat-run idempotency, combined inspection filters, and equivalent direct-observer/replay lifecycle projections. HTTP transport remained faked and unused by observer/replay paths.

## 6. Run regression gates

```powershell
php artisan test tests/Feature/TelegramAssistantServiceTest.php
php artisan test tests/Feature/TelegramForumDigestPreviewCommandTest.php
php artisan test tests/Feature/OperationalPreviewCommandTest.php
vendor/bin/pint --test
git diff --check
```

Expected: activated assistant requests and their replies retain existing behavior; forum digest and operational preview contracts are unchanged; formatting and whitespace checks pass.
