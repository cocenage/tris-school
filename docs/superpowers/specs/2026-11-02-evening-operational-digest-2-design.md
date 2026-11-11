# Evening Intelligence 2.0 — design

Date: 2026-11-02

## Scope

Improve only the existing Evening Intelligence builder/formatter and existing linen report builder/formatter. Preserve the OI ledger, Telegram routes, the 20:30 Europe/Rome schedule, its [00:00, 20:30) window, delivery idempotency, and source history. Do not touch Scheduled Controls, calendar, celebrations, Focus, or unrelated Telegram behavior.

## Decisions

1. Treat each existing OI event key as the authoritative incident boundary. Do not merge separate events by address, time, item, or wording. A single event is rendered once across the management sections, while its supported classifications and linked evidence remain available on that one rendered item.
2. Choose a single display section using evidence-backed priority (technical fault, significant problem, instruction gap, praise). Show additional supported classifications/context on the same item rather than rendering a duplicate incident. Do not create praise or action recommendations from weak evidence.
3. Distinguish a confirmed resolution only when the existing event/evidence state supports it. For an event without confirming resolution, say that resolution is unconfirmed or requires clarification; do not imply that silence proves either resolution or current failure.
4. Treat one ledger event as one linen case. Parse item types and explicit quantities from the existing source evidence. Count case records, distinct item types, and explicitly stated units as separate measures. Unknown quantities remain unknown; they do not become one. Do not merge separate event keys merely because location/item/time match.
5. Keep source completeness conservative without a schema change. A configured source with zero stored messages is no messages recorded; receipt completeness is unconfirmed, not a confirmed empty day. Missing route/ledger is unavailable; some unprocessed messages are partial; all recorded messages processed allows a confirmed no-significant-findings result only when there is recorded input and no qualifying event.
6. Preserve existing report dates, district routes, links, and pre-20:30 cutoff. No production reads, sends, replay, or data edits are part of implementation verification.

## Implementation outline

- Add regressions around cross-section event uniqueness, explicit resolution/uncertainty wording, the reported linen quantity semantics, and preventing false merges between distinct events.
- Make the narrow builder/formatter changes needed to satisfy those tests.
- Use isolated synthetic OI fixtures for test assertions. If a local saved-data preview is available, label it separately from fixture evidence; do not call fixtures real production reports.
- Run only relevant Pest targets, Pint for touched PHP files, and git diff --check; then create one focused commit, push the feature branch, and provide a PR link or manual-creation URL.
