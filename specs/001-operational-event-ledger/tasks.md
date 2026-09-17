---

description: "Actionable task list for TRIS AI Operational Event Ledger"
---

# Tasks: TRIS AI Operational Event Ledger

**Input**: Design documents from `specs/001-operational-event-ledger/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Acceptance tests are required by Spec 001 and the project workflow. Write each test task first and confirm its scenario fails before implementing the matching behavior.

**Organization**: Tasks are grouped by user story and ordered to reach a one-historical-day replay checkpoint inside User Story 1 before live observation is enabled.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files and does not depend on an incomplete task.
- **[Story]**: Maps the task to User Story 1, 2, or 3 from `spec.md`.
- Every task names its exact target file or files.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add only the independent rollout switch required to deploy and validate the silent observer safely.

- [X] T001 Add `services.telegram.operational_observer_enabled` with a default of `false` in `config/services.php` and document `TELEGRAM_OPERATIONAL_OBSERVER_ENABLED=false` in `.env.example` without changing `.env` or coupling it to `assistant_enabled`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Create the minimum analytics-only ledger that all three stories share.

**Critical**: Do not run the migration against production as part of implementation.

- [X] T002 [P] Create `database/migrations/2026_09_16_000000_create_telegram_operational_event_ledger.php` on `Schema::connection('analytics')` with: events requiring unique `event_key`, required root message/chat/primary type/type list/summary/status/confidence/times, nullable topic/uncertainty/subject key/resolved time, statuses `open|resolved|reopened|dismissed`, confidence `low|medium|high`, and the indexes from `data-model.md`; observations requiring message/revision hash/evaluation kind/state, unique `(telegram_message_id, source_revision_hash, evaluation_kind)`, kinds `message|unanswered`, states `processing|completed|failed`, the documented outcome values, nullable safe reason/confidence/uncertainty/deadline/error fields, and current-revision/deadline indexes; evidence requiring event/observation/role/transition/status-after/confidence/occurred-at/current-revision, nullable status-before/uncertainty, unique `(operational_event_id, observation_id, role, transition)`, and ordered-history index
- [X] T003 [P] Add analytics-connected models, casts, fillable fields, and relationships exactly matching `data-model.md` in `app/Models/TelegramOperationalEvent.php`, `app/Models/TelegramOperationalObservation.php`, `app/Models/TelegramOperationalEventEvidence.php`, and add only observation/evidence relationships to `app/Models/TelegramMessage.php`

**Checkpoint**: The ledger schema and models exist without changing assistant-request tables or primary operational records.

---

## Phase 3: User Story 1 — Observe Meaningful Operations Silently (Priority: P1) MVP

**Goal**: Selectively create traceable initial events or durable no-event decisions, remain silent, and prove the vertical slice on one historical day before enabling live dispatch.

**Independent Test**: Replay one reviewed historical-day fixture containing ordinary chat, all ten event types, uncertainty, duplicate messages, and four-hour unanswered-question boundaries; verify expected events/evidence/no-event decisions and zero outbound Telegram activity.

### Tests for User Story 1

- [X] T004 [P] [US1] Create failing interpreter tests for ordinary/empty/bot/service messages, all Spec 001 event types, existing assistant-category reuse, conservative confidence/uncertainty, explicit resolution/action/question signals, and the structured decision contract in `tests/Unit/TelegramOperationalInterpreterTest.php`
- [X] T005 [P] [US1] Create failing observer tests for initial event creation, stable root-derived event keys, evidence traceability, no-event reason codes, unchanged duplicate idempotency, legacy array-or-JSON `raw` handling, atomic failure/retry, and pending-versus-mature four-hour questions in `tests/Feature/TelegramOperationalEventObserverTest.php`
- [X] T006 [P] [US1] Create failing one-day replay and inspection contract tests covering `--date`, `sent_at ASC, id ASC`, bounded chunking, required counters/event keys, raw/full-text omission, evidence inspection, repeat-run idempotency, and zero HTTP/Telegram calls in `tests/Feature/TelegramOperationalReplayCommandTest.php` and `tests/Feature/TelegramOperationalEventsCommandTest.php`

### Implementation for User Story 1

- [X] T007 [US1] Implement the side-effect-free structured interpreter in `app/Services/Telegram/TelegramOperationalInterpreter.php`, injecting the existing `TelegramAssistantClassifier` as a category signal, supplementing only the missing conservative event/role/question/resolution signals, returning every field required by `contracts/observer-result.md`, and making no network, bot, queue, or database call
- [X] T008 [US1] Implement initial observation in `app/Services/Telegram/TelegramOperationalEventObserver.php`: enforce work-chat/private/bot/service/content eligibility; normalize array or JSON-string `raw`; hash interpretation-relevant revisions; atomically persist completed/failed observations, initial events, and evidence; reuse completed keys; retain low-confidence uncertainty; return four-hour unanswered deadlines; and never resolve ambiguously or call Telegram/external services
- [X] T009 [US1] Implement the one-day `--date` vertical slice and read-only inspection contract in `app/Console/Commands/TelegramOperationalReplayCommand.php` and `app/Console/Commands/TelegramOperationalEventsCommand.php`, using the same observer synchronously, processing equal timestamps before due unanswered maturation, emitting the documented counters/source identifiers with `telegram_actions=0`, bounding unfiltered inspection output, and omitting raw payloads/full message bodies
- [X] T010 [P] [US1] Extend `tests/Feature/TelegramWorkWebhookTest.php` and create `tests/Feature/TelegramAnalyticsWebhookTest.php` with failing tests that the independent flag dispatches exactly the reusable observer job after each successful persistence path, duplicate dispatch stays safe, disabled mode preserves current behavior, private/disallowed updates create no event, and no observer path invokes Telegram transport
- [X] T011 [US1] Implement `app/Jobs/ProcessTelegramOperationalMessage.php` with bounded retries/timeout and `message|unanswered` modes, schedule the same job at the observer-returned four-hour deadline, and dispatch it behind the independent flag after completed persistence/attachment handling in `app/Http/Controllers/TelegramWorkWebhookController.php` and `app/Http/Controllers/TelegramAnalyticsWebhookController.php` without changing ingestion or assistant reply flow
- [X] T012 [US1] Run the focused US1 tests and execute the one-day fixture workflow documented in `specs/001-operational-event-ledger/quickstart.md`; record the reviewed-corpus SC-004/SC-005 result in `specs/001-operational-event-ledger/quickstart.md` and stop rather than adding an external AI provider or weakening criteria if the acceptance thresholds fail

**Checkpoint**: A selected historical day produces selective, evidence-linked initial events and explainable no-event decisions; replay is repeatable and silent; only then may the live flag be enabled in an authorized environment.

---

## Phase 4: User Story 2 — Track One Event Across Messages (Priority: P2)

**Goal**: Correlate supported messages into one event and preserve deterministic update, resolution, reopening, uncertainty, and correction history.

**Independent Test**: Process a chronological conversation containing report, detail/action, clear resolution, recurrence, ambiguous resolution, unrelated lookalikes, and an edited root message; verify one correct lifecycle with ordered evidence and no false merge.

### Tests for User Story 2

- [X] T013 [US2] Add failing correlation/lifecycle tests for explicit reply precedence, same-chat/topic and compatible subject/type matching within seven days, ambiguous-candidate no-merge behavior, update/evidence/resolve/reopen transitions, same-timestamp ordering, changed-revision correction, retained prior history, current-evidence reprojection, and `dismissed` only when no current support remains in `tests/Feature/TelegramOperationalEventObserverTest.php`

### Implementation for User Story 2

- [X] T014 [US2] Extend `app/Services/Telegram/TelegramOperationalEventObserver.php` to apply the approved conservative correlation order, lock/update candidates transactionally, append evidence-backed `updated|evidence|resolved|reopened|dismissed` transitions, require confirming evidence for resolution, supersede edited interpretations without deleting history, and keep the current event projection and confidence/uncertainty consistent with `data-model.md`

**Checkpoint**: Multiple supported messages form one inspectable lifecycle, while unrelated or ambiguous situations remain separate or explicitly uncertain.

---

## Phase 5: User Story 3 — Replay a Short Historical Period (Priority: P3)

**Goal**: Complete bounded one-to-seven-day replay with deterministic lifecycle parity and developer inspection.

**Independent Test**: Run the same reviewed fixture through live observation and replay in isolated analytics stores, rerun replay, and verify identical logical event keys, states, transitions, confidence, evidence, zero duplicates, and zero Telegram/primary-record writes.

### Tests for User Story 3

- [X] T015 [US3] Extend `tests/Feature/TelegramOperationalReplayCommandTest.php` with failing tests for exactly-one-selector validation, paired `--from/--to`, past-only inclusive one-to-seven-day bounds rejected before reads/writes, chunked `sent_at/id` chronology, equal-timestamp response-before-maturation behavior, range-end pending deadlines, per-message failure counts, repeat replay idempotency, and live/replay lifecycle parity; extend `tests/Feature/TelegramOperationalEventsCommandTest.php` for combined event/message/date/status filters and read-only current/history/failure output

### Implementation for User Story 3

- [X] T016 [US3] Complete `app/Console/Commands/TelegramOperationalReplayCommand.php` with paired range validation before query/write, work-chat and configured-allowlist eligibility, chunked synchronous observation, timestamp-grouped logical-clock unanswered maturation, safe continuation/counting of message failures, and the complete human/JSON contract; complete filter and ordered evidence/decision projections in `app/Console/Commands/TelegramOperationalEventsCommand.php`
- [X] T017 [US3] Execute the replay-boundary, duplicate-run, live/replay parity, inspection, and zero-external-write scenarios from `specs/001-operational-event-ledger/quickstart.md` and update that file only if the actual commands or verified expected outputs differ from the approved contract

**Checkpoint**: Spec 001 replay and inspection are complete without archive-wide processing or a new UI/API.

---

## Phase 6: Polish & Cross-Cutting Verification

**Purpose**: Prove the new layer did not change existing assistant, digest, context, or transport behavior.

- [X] T018 Run `tests/Feature/TelegramAssistantServiceTest.php`, `tests/Feature/TelegramForumDigestPreviewCommandTest.php`, `tests/Feature/OperationalPreviewCommandTest.php`, all new focused tests, `vendor/bin/pint --test`, and `git diff --check`; inspect the final diff to confirm no changes to assistant request schemas, `app/Services/Telegram/TelegramAssistantService.php`, `app/Services/Telegram/TelegramForumDigestBuilder.php`, `app/Services/Operations/OperationalContextBuilder.php`, `routes/console.php` scheduler entries, primary operational models/data, or Telegram sending behavior

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Starts immediately.
- **Foundational (Phase 2)**: Starts after T001; T002 and T003 may run in parallel and block all stories.
- **User Story 1 (Phase 3)**: Starts after T002–T003 and is the MVP. T004–T006 may run in parallel; T007 precedes T008; T008 precedes T009; T010 precedes T011; T012 requires T009 and T011.
- **User Story 2 (Phase 4)**: Requires the US1 observer/evidence slice; T013 must fail before T014 is implemented.
- **User Story 3 (Phase 5)**: Requires US1 replay plus US2 lifecycle behavior; T015 must fail before T016, and T017 validates the completed story.
- **Polish (Phase 6)**: Runs after the desired user-story phases are complete.

### User Story Dependency Graph

```text
Setup → Foundation → US1 (silent initial ledger + one-day replay)
                            ↓
                           US2 (correlation and lifecycle)
                            ↓
                           US3 (bounded replay and parity)
                            ↓
                         Verification
```

### Parallel Opportunities

- T002 and T003 can be implemented in parallel after T001 because they touch migration and model files separately.
- T004, T005, and T006 can be written in parallel after the foundation exists.
- T010 can be prepared while T007–T009 are being implemented because it touches webhook test files rather than interpreter/observer/command files.
- Within T015, replay and inspection test additions target separate test files and can be split between implementers if their shared fixtures are agreed first.

## Parallel Example: User Story 1

```text
Task T004: Write interpreter contract tests in tests/Unit/TelegramOperationalInterpreterTest.php
Task T005: Write observer persistence/idempotency tests in tests/Feature/TelegramOperationalEventObserverTest.php
Task T006: Write one-day replay/inspection tests in tests/Feature/TelegramOperationalReplayCommandTest.php and tests/Feature/TelegramOperationalEventsCommandTest.php
```

## Parallel Example: User Story 2

```text
No implementation tasks are marked parallel: T014 changes the same observer behavior exercised by T013 and begins only after those tests fail for the expected reasons.
```

## Parallel Example: User Story 3

```text
Within T015, one implementer can add replay range/parity tests in tests/Feature/TelegramOperationalReplayCommandTest.php while another adds read-only filter tests in tests/Feature/TelegramOperationalEventsCommandTest.php; merge the fixtures before T016.
```

## Implementation Strategy

### MVP First: User Story 1

1. Add the disabled-by-default flag and analytics ledger foundation.
2. Write the US1 interpreter, observer, and one-day replay tests first.
3. Implement interpreter → observer → one-day replay/inspection.
4. Validate one reviewed historical day and the SC-004/SC-005 thresholds.
5. Only after that checkpoint, connect the reusable job to both existing persistence paths.
6. Stop after US1 if selective accuracy, silence, evidence, or idempotency is not proven.

### Incremental Delivery

1. **US1**: Initial events/no-event decisions, evidence, unanswered maturation, one-day replay, inspection, and opt-in live observation.
2. **US2**: Same-event correlation, lifecycle, and edited-message correction.
3. **US3**: Full one-to-seven-day replay, logical-clock parity, and complete inspection filters.
4. **Verification**: Existing assistant/digest/context behavior and quality gates.

## Notes

- `[P]` means different files and no dependency on incomplete work; it does not waive story checkpoints.
- No task may introduce an AI provider, new webhook, new scheduler entry, Filament UI, public API, replay-run table, task/action automation, or changes to morning/evening summaries.
- Use the separate `analytics` connection for every ledger query/write and keep existing TRIS operational records read-only.
- The live flag stays disabled until the one-day replay checkpoint passes in an authorized environment.
