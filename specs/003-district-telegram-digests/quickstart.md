# Quickstart Validation: District Telegram Digests

All validation uses local/test configuration. Do not supply production tokens and do not enable real delivery.

## 1. Targeted automated checks

Run the evening builder/formatter/preview/send tests, district route tests, weather tests, and mobility digest tests. Expected: all pass, HTTP sender calls are zero in preview/dry-run/disabled/empty scenarios.

## 2. Historical evening preview

Run the existing preview for reviewed date `2026-07-23` in human and JSON modes.

Expected human result: concise title and bullet sections; no event keys, evidence/message IDs, confidence, status, transitions, or technical timestamps; no event repeated across sections; no low-confidence noise or context-free resolution.

Expected JSON result: complete technical event list; evidence and lifecycle details retained; read-only mode and zero Telegram actions.

## 3. District evening dry-run

Use process-local non-production district routes resolved from the local analytics copy, then dry-run one representative district/day.

Expected: selected forum only, duty destination identified, zero Telegram requests and zero mutations.

## 4. Empty evening check

Dry-run a configured district/date with no qualifying ledger events.

Expected: `skipped_empty`, zero sender calls.

## 5. Two-district morning check

Use test routes with distinct coordinates and weather HTTP fakes, plus district-specific and city-wide mobility fixtures.

Expected: two weather coordinate pairs, district-specific item only in its matching digest, city-wide item in both, matching duty destinations, zero sends in dry-run.

## 6. Safety and quality gates

- Confirm evening feature flag default is off.
- Confirm route validation covers `district -> chat_id -> duty_thread_id` without printing actual identifiers.
- Run scoped Pint for changed PHP files.
- Run the relevant/full test suite as broadly as practical.
- Run `git diff --check`.
