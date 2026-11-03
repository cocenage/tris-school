# Evening Intelligence 2.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Make the existing evening district report concise, evidence-grounded, non-duplicative, and honest about source completeness and linen quantities.

**Architecture:** Reuse the existing Analytics OI event/evidence ledger as the incident boundary. Update only the existing evening builder, linen builder, and their formatters; preserve all source records and routes, and do not add polling persistence or a migration.

**Tech Stack:** Laravel 12, PHP, Eloquent on the separate `analytics` connection, Carbon Europe/Rome, Pest.

**Spec:** `docs/superpowers/specs/2026-11-02-evening-operational-digest-2-design.md`

## Global Constraints

- Keep the existing 20:30 Europe/Rome schedule and half-open `[00:00, 20:30)` report window.
- Do not modify Scheduled Controls, calendar, celebrations, Focus, polling behavior, OI observer correlation, or production.
- Do not change Analytics connection selection or write/replay OI source data.
- Preserve source links and status evidence; do not infer resolution, praise, quantities, or recommendations.
- Never merge distinct OI event keys based only on apartment, item, time, or similar wording.
- Run only focused Pest targets, scoped Pint, and `git diff --check`; no production sends or replay.

## Review Focus

- One OI event is in more than one management section: the test must assert one rendered entry and all supported classifications/evidence remain on that entry.
- A configured district has no stored messages: the test must assert source receipt is unconfirmed and no clean-day success is claimed.
- Several items/cases have unknown counts: tests must assert unknown quantities stay null, case/detail counts stay distinct, and no implicit unit count is added.
- Same apartment/item/time but separate event keys: the test must assert cases remain separate absent explicit OI linkage.
- Data after the 20:30 cutoff: tests must assert it cannot alter the dated report.

---

### Task 1: Render each management event once

**Files:**
- Modify: `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php`
- Modify: `app/Services/Telegram/TelegramDigestFormatter.php`
- Test: `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`
- Test: `tests/Unit/TelegramDigestFormatterTest.php`

**Interfaces:**
- Builder keeps its existing `build()` return shape and `management_sections` keys.
- Each rendered incident retains its `event_key`, all supported classifications, source URL(s), status and supported follow-up evidence.

- [ ] Add a failing builder/formatter regression with one technical OI event that also qualifies as a daily problem; assert it appears only once in final formatted text and both classifications remain represented.
- [ ] Run the focused test and verify it fails because the same event is rendered in both sections.
- [ ] Consolidate section membership by `event_key`, preserving classification labels and unique source links on the selected item; do not alter OI event grouping.
- [ ] Add a failing status regression: absent explicit resolution must not display as confirmed open/solved; resolved requires existing confirming evidence.
- [ ] Run the focused status test and verify it fails on current rendering.
- [ ] Implement conservative status wording in the formatter, keeping unresolved/needs-clarification separate from confirmed resolution.
- [ ] Run the targeted tests for builder and formatter; expected result PASS.

### Task 2: Correct linen counts and keep unrelated incidents separate

**Files:**
- Modify: `app/Services/Telegram/TelegramLinenDefectReportBuilder.php`
- Modify: `app/Services/Telegram/TelegramLinenDefectReportFormatter.php`
- Modify: `app/Services/Telegram/TelegramDigestFormatter.php`
- Test: `tests/Feature/TelegramDailyReportingWindowTest.php` or existing focused linen tests in `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`
- Test: `tests/Unit/TelegramDigestFormatterTest.php`

**Interfaces:**
- Linen report returns explicit case count, detail/position count, confirmed units, and unknown-quantity detail count.
- Each case continues to carry its OI `event_key`, district, location, defect, details, and source URL.

- [ ] Add a failing report test with two distinct OI cases and three textile details with unspecified quantities; assert two cases, three details, and zero confirmed units.
- [ ] Run that test and verify current counters mislabel details as cases.
- [ ] Add a failing separation test with distinct OI event keys for courier linen and defective textile at the same location; assert two independent cases and no joined details.
- [ ] Run that test and verify it fails only if cases are merged or relation is inferred without evidence.
- [ ] Implement explicit metrics; sum only stated quantities, retain unknown detail quantities, and preserve distinct event cases.
- [ ] Update both existing linen renderers to report cases, item details, and confirmed units with accurate labels and source links.
- [ ] Run focused linen builder/formatter tests; expected result PASS.

### Task 3: Report source completeness conservatively

**Files:**
- Modify: `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php`
- Modify: `app/Services/Telegram/TelegramDigestFormatter.php`
- Modify: `app/Services/Telegram/TelegramLinenDefectReportBuilder.php` only if it consumes the new quality state
- Test: `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`
- Test: `tests/Feature/TelegramDailyReportingWindowTest.php`
- Test: `tests/Unit/TelegramDigestFormatterTest.php`

**Interfaces:**
- Add a distinct `no_messages` source status for a configured source with zero stored messages. Keep `unavailable`, `incomplete`, and `available` distinct.
- `no_messages` explicitly means receipt completeness is unconfirmed; it does not mean the poller successfully observed zero messages.

- [ ] Add failing tests for: configured zero-message source; all recorded messages processed with no significant event; partially observed source; missing source configuration.
- [ ] Run the focused tests and verify zero-message source currently conflates with unavailable and/or a conclusive empty report.
- [ ] Implement the separate status and truthful Russian copy; only report confirmed no significant findings when input exists and is completely processed.
- [ ] Preserve the current reporting cutoff and assert post-cutoff messages do not affect source status or content.
- [ ] Run the focused quality/window/formatter tests; expected result PASS.

### Task 4: Verify district previews and release hygiene

**Files:**
- No production or schedule files.
- Tests limited to files listed in Tasks 1–3.

- [ ] Run the targeted Pest files: `vendor/bin/pest tests/Unit/TelegramEveningIntelligenceBuilderTest.php tests/Unit/TelegramDigestFormatterTest.php tests/Feature/TelegramDailyReportingWindowTest.php`.
- [ ] Run scoped Pint on changed PHP files.
- [ ] Run `git diff --check`.
- [ ] Generate five read-only local previews for 2026-11-01 only if the local Analytics DB is positively identified as non-production; label them as local saved-data output or synthetic fixtures, never as production reports.
- [ ] Review the final diff for only the approved Evening Intelligence/linen behavior and tests; commit and push the feature branch, then prepare a PR to `main`.

## Expected changed files

- `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php`
- `app/Services/Telegram/TelegramLinenDefectReportBuilder.php`
- `app/Services/Telegram/TelegramDigestFormatter.php`
- `app/Services/Telegram/TelegramLinenDefectReportFormatter.php`
- Focused tests named above
- This plan and its approved design

## Completion evidence

Report the actual test command/exit status, Pint and diff-check results, local preview provenance, files changed, commit SHA, branch and PR link. State clearly that production and real Telegram sends were untouched.
