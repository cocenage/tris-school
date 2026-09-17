# Implementation Plan: TRIS Assistant Evening Intelligence

**Branch**: `002-evening-intelligence` | **Date**: 2026-09-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-evening-intelligence/spec.md`

## Summary

Add one deterministic, read-only evening intelligence projection over the existing Spec 001 operational event ledger. A new preview command accepts one explicit date, a focused builder reconstructs each touched event as of that day's end from current evidence and observations, and the existing digest formatter renders only supported non-empty sections. The first checkpoint is one historical date already present in the ledger, producing evidence-backed JSON and human output without Telegram delivery, mutations, new storage, or a parallel analytics path.

## Technical Context

**Language/Version**: PHP ^8.2 (project platform PHP 8.3; local validation uses the project Herd PHP runtime)

**Primary Dependencies**: Laravel 12, Eloquent, Carbon, existing `TelegramOperationalEvent`, `TelegramOperationalEventEvidence`, `TelegramOperationalObservation`, and `TelegramDigestFormatter`

**Storage**: Existing SQLite `analytics` connection and Spec 001 ledger tables; no new tables, columns, or persisted summary records

**Testing**: Pest 4 with the existing in-memory analytics test support, Artisan command tests, and historical read-only preview validation

**Target Platform**: Existing TRIS Laravel application and local/server CLI

**Project Type**: Laravel monolith with an internal read-only CLI preview

**Performance Goals**: Build one selected-day preview in under five seconds on representative local ledger data; keep the resulting management view readable in under three minutes

**Constraints**: Explicit single date; application timezone; ledger-first; evidence-backed; no raw Telegram volume summary; no writes, scheduling, delivery, external calls, employee scoring, or autonomous actions

**Scale/Scope**: One calendar day per invocation, using only events with current evidence relevant to that day and their evidence history up to the selected day boundary

## Constitution Check

*GATE: Passed before Phase 0 and re-checked after Phase 1.*

The repository constitution is still an unfilled template and defines no enforceable project-specific gates. The operative repository rules and recorded decisions therefore supply the gates for this plan:

- **Existing architecture**: PASS — reads the Spec 001 ledger through its existing analytics models and does not create another Telegram or analytics pipeline.
- **Read-only operational context**: PASS — preview performs queries and formatting only; no model save, queue dispatch, external service, Telegram transport, or scheduler path is introduced.
- **Separate analytics connection**: PASS — ledger models remain on `analytics`; no data moves to the primary database.
- **Scope discipline**: PASS — no morning-summary changes, ingestion changes, task/action features, scoring, UI work, or unrelated refactoring.
- **Real-scenario validation**: PASS — the first integration checkpoint is a selected historical date already represented by Spec 001 evidence.
- **No schema expansion without need**: PASS — existing event, evidence, and observation fields are sufficient for the transient projection.

Post-design re-check: PASS. The data model is transient, the only contract is a preview CLI/JSON response, and validation explicitly checks zero mutations and zero network delivery.

## Project Structure

### Documentation (this feature)

```text
specs/002-evening-intelligence/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── evening-intelligence-preview.md
└── tasks.md                         # created later by $speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   └── TelegramEveningIntelligencePreviewCommand.php
└── Services/Telegram/
    ├── TelegramEveningIntelligenceBuilder.php
    └── TelegramDigestFormatter.php               # add one dedicated rendering method

tests/
├── Feature/
│   └── TelegramEveningIntelligencePreviewCommandTest.php
├── Unit/
│   └── TelegramEveningIntelligenceBuilderTest.php
└── Support/
    └── TelegramOperationalTestDatabase.php       # reuse; extend only if a fixture helper is needed
```

**Structure Decision**: Keep the feature inside the existing Telegram service and Artisan preview conventions. The builder is new because the existing forum digest is raw-message/topic based; the formatter and Spec 001 analytics models are reused. No controller, page, job, migration, or delivery service is added.

## Design

### Read path

1. The command requires `--date=YYYY-MM-DD`, validates it strictly in the application timezone, and calls the builder.
2. The builder selects events having current evidence whose `occurred_at` falls within the selected day.
3. For each selected event, it loads only current evidence and observations up to the selected day's end, ordered by `occurred_at` and evidence id.
4. It derives an end-of-day projection without changing the stored event:
   - status from the last eligible evidence `status_after`;
   - event types from observation `reason_code` values plus the explicit unanswered-question maturation code, using `primary_type` only as the stable base type;
   - confidence conservatively as the weakest eligible evidence confidence;
   - uncertainty from non-empty eligible evidence uncertainty values;
   - evidence references from message identity, role, transition, and occurrence time, never raw payloads.
5. Dismissed end-of-day events are omitted. Open and reopened events are unresolved; resolved events are shown as resolved only when their as-of-day evidence supports it.
6. The builder assigns event-backed items to non-empty sections and produces advisory tomorrow-attention items only from qualifying unresolved events.
7. `TelegramDigestFormatter` renders the contract for humans; `--json` returns the same contract without formatting loss.

### Inclusion and ordering

- Include events that were created, materially updated, resolved, reopened, or matured as unanswered on the selected date.
- Do not pull unrelated open backlog with no selected-day evidence.
- Preserve low/medium confidence in wording. Low-confidence events may be included only when their unresolved type is operationally material; they remain explicitly qualified.
- Treat repeated activity only within one ledger event when multiple reports/recurrences or a reopening support it. Do not merge separate events or infer a cross-event trend in this stage.
- Order unresolved attention before resolved information; within a section use a fixed operational type order, then confidence, then latest selected-day evidence time, then event key for deterministic ties.
- Include positive contributions only when the ledger type is `positive_contribution`; never infer them from praise or raw Telegram text.
- Omit empty sections. If nothing qualifies, return one no-material-events statement.

### Existing components deliberately not repurposed

- `TelegramForumDigestBuilder` remains unchanged because its contract summarizes raw forum/topic volume and lexical signals, not operational events.
- `OperationalContextBuilder` remains unchanged. Its application-timezone and read-only conventions are followed, but its raw Telegram counters do not create or prioritize summary items. A future context claim requires an explicit event-backed correlation rule; Spec 002 does not invent one.
- `TelegramOperationalReplayCommand`, observer, interpreter, ledger models, and migrations remain unchanged.
- Existing `TelegramDigestFormatter::evening()` remains available for the prior forum preview; a separate method prevents behavioral regression.

## Validation Strategy

### Earliest usable checkpoint

1. Choose one historical date already containing Spec 001 ledger evidence.
2. Inspect that date with the existing operational-events command.
3. Run the new evening intelligence preview for exactly that date in JSON and human modes.
4. Verify each factual item resolves to the listed event and evidence messages, lifecycle is correct as of day end, uncertainty is preserved, empty sections are absent, and no data/network changes occur.

### Automated coverage

- one event created and still unresolved;
- event resolved on the selected day;
- event reopened after an earlier resolution;
- later-day resolution does not alter the earlier-day preview;
- unanswered-question maturation and answered-question exclusion;
- quality, risk, delay, and meaningful positive contribution placement;
- low-confidence wording and exclusion rules;
- multiple evidence messages remain traceable;
- dismissed and unrelated-backlog events are omitted;
- no qualifying events returns the single empty-day result;
- invalid or missing date fails before querying;
- JSON and human output contain no raw payload and human output omits empty headings;
- table row counts/timestamps and recorded HTTP calls remain unchanged.

### Quality gates

- Run the new unit and feature tests first.
- Run the existing Spec 001 observer, replay, and inspection tests plus forum/operational preview tests to protect reuse boundaries.
- Run scoped formatting, then `git diff --check`.
- Run the historical acceptance checkpoint on one day before considering additional historical days.

## Complexity Tracking

No constitution violation or exceptional complexity is required. The feature adds one query/projection service, one CLI adapter, and one formatter method, with no persistence or external integration.
