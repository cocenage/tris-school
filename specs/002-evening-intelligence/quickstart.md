# Quickstart: Validate Evening Intelligence

## Prerequisites

- Spec 001 ledger migration is already applied on the local analytics connection.
- A historical date has existing operational event evidence, produced by the approved Spec 001 observation/replay tooling.
- Live observation remains disabled; no Telegram delivery or queue worker is running.
- Use the project PHP runtime for all commands.

## 1. Run targeted automated checks

```powershell
& 'C:\Users\303wa\.config\herd\bin\php84\php.exe' artisan test tests/Unit/TelegramEveningIntelligenceBuilderTest.php tests/Feature/TelegramEveningIntelligencePreviewCommandTest.php
```

Expected: all projection, traceability, historical lifecycle, uncertainty, empty-section, invalid-date, and read-only scenarios pass.

## 2. Protect existing preview and ledger behavior

```powershell
& 'C:\Users\303wa\.config\herd\bin\php84\php.exe' artisan test tests/Feature/TelegramOperationalEventObserverTest.php tests/Feature/TelegramOperationalReplayCommandTest.php tests/Feature/TelegramOperationalEventsCommandTest.php tests/Feature/TelegramForumDigestPreviewCommandTest.php tests/Feature/OperationalPreviewCommandTest.php
```

Expected: existing Spec 001 and preview tests remain green.

## 3. Select one historical ledger date

Use a previously validated Spec 001 date with real event evidence. Confirm the source ledger first:

```powershell
& 'C:\Users\303wa\.config\herd\bin\php84\php.exe' artisan telegram:operational-events --date=YYYY-MM-DD --json
```

Expected: event keys and evidence references are visible without raw Telegram payloads.

## 4. Run the earliest usable checkpoint

```powershell
& 'C:\Users\303wa\.config\herd\bin\php84\php.exe' artisan telegram:evening-intelligence-preview --date=YYYY-MM-DD --json
& 'C:\Users\303wa\.config\herd\bin\php84\php.exe' artisan telegram:evening-intelligence-preview --date=YYYY-MM-DD
```

Expected:

- both modes use the same selected day and event set;
- each factual item names an event key and evidence message reference;
- resolved, unresolved, reopened, and unanswered states reflect evidence as of that day's end;
- uncertainty is explicit;
- trivial/dismissed events and empty sections are absent;
- no raw message-volume summary appears;
- output states that the mode is read-only and Telegram actions are zero.

## 5. Verify no mutation or delivery

Before and after the preview, compare row counts and latest update timestamps for:

- `telegram_operational_events`;
- `telegram_operational_observations`;
- `telegram_operational_event_evidence`;
- source `telegram_messages`.

All values must remain unchanged. Application HTTP recording/log inspection must show no Telegram request caused by either preview invocation.

## 6. Review against the specification

For every human-output statement, locate the matching JSON item, event key, evidence references, and existing ledger history. Confirm the acceptance measures in [spec.md](spec.md) and the response rules in [contracts/evening-intelligence-preview.md](contracts/evening-intelligence-preview.md).

Do not enable live observation, add a schedule, send the output, or modify the production allowlist during this validation.
