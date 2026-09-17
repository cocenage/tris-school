---

description: "Minimal actionable tasks for TRIS Assistant Evening Intelligence"
---

# Tasks: TRIS Assistant Evening Intelligence

**Input**: Design documents from `specs/002-evening-intelligence/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/evening-intelligence-preview.md](contracts/evening-intelligence-preview.md), [quickstart.md](quickstart.md)

**Tests**: Tests are included because the specification requires historical lifecycle accuracy, evidence traceability, omission quality, and zero-write/zero-delivery acceptance checks.

**Organization**: Tasks are grouped by user story and ordered to reach the first real historical-day preview immediately after the P1 vertical slice.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes a different file and has no dependency on another incomplete task in the same phase
- **[Story]**: Maps the task to US1, US2, or US3 from the specification
- Every task names the exact file or validation artifact it affects

## Phase 1: Setup and Baseline

**Purpose**: Confirm the reused Spec 001 ledger and preview conventions are green before any Spec 002 change.

- [X] T001 Run the existing ledger/replay/inspection and preview baseline tests in `tests/Feature/TelegramOperationalEventObserverTest.php`, `tests/Feature/TelegramOperationalReplayCommandTest.php`, `tests/Feature/TelegramOperationalEventsCommandTest.php`, `tests/Feature/TelegramForumDigestPreviewCommandTest.php`, and `tests/Feature/OperationalPreviewCommandTest.php`; stop if the baseline contradicts `specs/002-evening-intelligence/plan.md`

---

## Phase 2: Foundation

**Purpose**: Reuse the existing foundation without introducing infrastructure.

No new foundational task is required. Spec 001 already provides the analytics connection, ledger schema, models, evidence relationships, replay, and test database support. Do not add migrations, models, configuration flags, queues, or delivery infrastructure.

**Checkpoint**: Existing Spec 001 baseline passes and the P1 slice can begin.

---

## Phase 3: User Story 1 - Preview the Day's Operational Picture (Priority: P1) 🎯 MVP

**Goal**: Produce a concise JSON and human preview for one explicit historical date from existing ledger events, with correct as-of-day lifecycle, uncertainty, non-empty sections, and no raw Telegram-volume summary.

**Independent Test**: For one reviewed historical date, compare the preview with `telegram:operational-events --date=YYYY-MM-DD --json`; verify important events are included, trivial/dismissed events are omitted, resolved and unresolved states reflect the selected day, uncertainty is qualified, and no data or network activity changes.

### Tests for User Story 1

- [X] T002 [P] [US1] Write failing projection tests for selected-day evidence filtering, historical end-of-day status, later-day transition exclusion, weakest-confidence selection, uncertainty preservation, dismissed/backlog omission, deterministic ordering, and the no-material-events result in `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`
- [X] T003 [P] [US1] Write failing command-contract tests for required strict `--date`, JSON/human modes, non-empty sections only, unavailable-ledger failure, zero HTTP calls, and unchanged ledger/source rows in `tests/Feature/TelegramEveningIntelligencePreviewCommandTest.php`

### Implementation for User Story 1

- [X] T004 [US1] Implement the read-only as-of-day projection in `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php`: candidates require current evidence on the selected date; eligible evidence is current and at or before day end; status comes from the last `status_after`; types come from stable `primary_type` plus observation `reason_code`; confidence uses `low < medium < high`; dismissed items are excluded; every included item carries at least one evidence reference
- [X] T005 [P] [US1] Add a dedicated deterministic evening-intelligence rendering method to `app/Services/Telegram/TelegramDigestFormatter.php` without changing `morning()` or the existing forum-based `evening()` behavior
- [X] T006 [US1] Add `telegram:evening-intelligence-preview --date=YYYY-MM-DD [--json]` in `app/Console/Commands/TelegramEveningIntelligencePreviewCommand.php`, using the existing application timezone and preview conventions, failing before projection on a missing/invalid date, and exposing no delivery, route, mutation, or scheduling option
- [X] T007 [US1] Run the earliest real-data checkpoint from `specs/002-evening-intelligence/quickstart.md` for one historical date already represented in the ledger; compare `app/Console/Commands/TelegramOperationalEventsCommand.php` output with both new preview modes and report inclusion, omission, lifecycle, uncertainty, evidence, runtime, and zero-mutation/zero-delivery results before proceeding

**Checkpoint**: A real historical date produces an evidence-backed read-only evening preview. This is the MVP and the required stop-and-validate point.

---

## Phase 4: User Story 2 - Trace Summary Items to Evidence (Priority: P2)

**Goal**: Make every factual item auditable from human and JSON output without exposing raw payloads or allowing unsupported context claims.

**Independent Test**: Follow every preview item to its event key and at least one current evidence message; confirm later evidence is excluded from an earlier date, raw payloads are absent, and an item with no valid evidence cannot appear.

### Tests and Implementation for User Story 2

- [X] T008 [US2] Extend `tests/Unit/TelegramEveningIntelligenceBuilderTest.php` and `tests/Feature/TelegramEveningIntelligencePreviewCommandTest.php` with failing traceability cases covering event keys, local/source message identities, roles, transitions, occurrence times, future-evidence exclusion, missing-evidence rejection, and raw-payload omission
- [X] T009 [US2] Enforce the traceability contract in `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php` and `app/Services/Telegram/TelegramDigestFormatter.php`: omit any unsupported item, preserve event/evidence references in JSON, show compact references in human output, and never derive an item from `OperationalContextBuilder` or raw forum counters alone

**Checkpoint**: Every factual statement is traceable and no unsupported or future-state claim is emitted.

---

## Phase 5: User Story 3 - Identify Tomorrow's Attention (Priority: P3)

**Goal**: Add conservative tomorrow-attention, repeated-event, risk/delay/quality, and meaningful-positive views without actions, scoring, or false cross-event grouping.

**Independent Test**: On a reviewed mixed-event day, verify tomorrow items come only from qualifying unresolved events, repetition is asserted only inside one correlated event, meaningful positives require the ledger type, uncertainty remains visible, and no task/action/delivery occurs.

### Tests for User Story 3

- [X] T010 [P] [US3] Add failing tomorrow-attention and section tests for unresolved problem/quality/risk/delay/unanswered events, same-event recurrence/reopening, resolved exclusion, ledger-backed positive contributions, generic-praise absence, no scoring fields, and no autonomous side effects in `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`

### Implementation for User Story 3

- [X] T011 [US3] Complete conservative section assignment and priority ordering in `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php` and section rendering in `app/Services/Telegram/TelegramDigestFormatter.php`: use only ledger evidence, never group separate events, omit empty sections, and make tomorrow output advisory text only

**Checkpoint**: All three user stories work with the same transient ledger-backed contract and no expanded product scope.

---

## Phase 6: Polish and Cross-Cutting Validation

**Purpose**: Prove compatibility and read-only behavior after all desired stories are complete.

- [X] T012 Run the targeted Spec 002 tests, the existing Spec 001 and preview regression tests, scoped Pint, and `git diff --check`, then rerun the historical validation steps in `specs/002-evening-intelligence/quickstart.md` on the MVP date and any additional already-populated representative dates; confirm no migration, table, queue, scheduler, Telegram request, allowlist change, or operational-record mutation was introduced

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Starts immediately and must pass before edits.
- **Foundation (Phase 2)**: Already supplied by Spec 001; no implementation task.
- **US1 (Phase 3)**: Starts after T001; T002 and T003 run in parallel, T004 and T005 can then run in parallel, T006 depends on T004 and T005, and T007 depends on T006.
- **US2 (Phase 4)**: Depends on the validated US1 checkpoint; T009 follows the failing cases in T008.
- **US3 (Phase 5)**: Depends on the shared US1 projection contract; T011 follows T010. It may run alongside US2 only if changes to the shared builder/formatter are coordinated.
- **Polish (Phase 6)**: Depends on all selected user stories.

### User Story Completion Order

```text
US1 historical preview MVP
├── US2 complete traceability
└── US3 tomorrow-attention views
    └── final regression and historical validation
```

- **US1** is the required first deliverable and contains the earliest real historical-data checkpoint.
- **US2** strengthens auditability without changing event selection or adding data sources.
- **US3** adds management follow-up views without changing the read-only contract.

### Within Each User Story

- Write the listed failing acceptance tests before implementation.
- Keep projection logic in the builder, presentation in the existing formatter, and input/output adaptation in the command.
- Run the story's independent test before starting the next story.
- Stop if real historical output contradicts the approved plan; do not invent a second classifier or storage path.

## Parallel Opportunities

### User Story 1

```text
Parallel: T002 builder tests | T003 command tests
Then parallel: T004 builder | T005 formatter
Then sequential: T006 command -> T007 real historical checkpoint
```

### User Story 2

No safe internal parallel split: T009 must respond to the traceability failures established by T008.

### User Story 3

T010 can be prepared after the US1 contract is stable; T011 follows it. US3 can proceed alongside US2 only with coordination because both touch the builder and formatter.

## Implementation Strategy

### MVP First

1. Complete T001.
2. Complete T002–T006 as one vertical slice.
3. Stop and execute T007 on one real historical date.
4. Accept the MVP only if every output item is evidence-backed and all read-only checks pass.

### Incremental Delivery

1. **US1**: selected date → current ledger evidence → as-of-day projection → JSON/human preview.
2. **US2**: prove and expose complete traceability; reject unsupported items.
3. **US3**: add conservative tomorrow/quality/risk/delay/positive views.
4. **Final**: regression suite and additional historical-date review without enabling live observation or delivery.

## Scope Guardrails

- Do not create or modify migrations, ledger tables, source Telegram tables, or persisted summary records.
- Do not call `TelegramForumDigestBuilder` or raw Telegram messages to decide intelligence content.
- Do not change `OperationalContextBuilder`, Spec 001 observer/interpreter/replay behavior, production allowlists, or live-observation flags.
- Do not add a queue job, scheduler entry, Telegram route/send path, task/action mutation, employee scoring/ranking, morning-summary behavior, or unrelated refactor.
