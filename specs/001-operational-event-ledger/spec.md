# Feature Specification: TRIS AI Operational Event Ledger

**Feature Branch**: `not-created (no before-specify hook configured)`

**Created**: 2026-09-15

**Status**: Draft

**Input**: First-stage silent observation of existing work Telegram messages, producing a traceable operational event ledger with lifecycle tracking and bounded historical replay.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Observe Meaningful Operations Silently (Priority: P1)

An operations developer can let TRIS observe already-ingested work Telegram communication so that meaningful problems, risks, requests, commitments, actions, resolutions, delays, unanswered operational questions, quality issues, and positive contributions become structured events, while ordinary conversation produces no event and no Telegram response.

**Why this priority**: A quiet, selective ledger is the core value of the first stage.

**Independent Test**: Process a reviewed chronological fixture containing ordinary chat and meaningful operational messages, then compare the resulting ledger and recorded outbound Telegram activity with the expected result.

**Acceptance Scenarios**:

1. **Given** an ordinary greeting or casual exchange, **When** it is observed, **Then** no operational event is created or changed.
2. **Given** a message that clearly reports an operational problem, **When** it is observed, **Then** one open problem event is recorded with confidence, timing, work-chat context, and that message as evidence.
3. **Given** any normal live observation, **When** processing completes, **Then** no message, reaction, notification, or other output is sent to Telegram.
4. **Given** an uncertain operational interpretation, **When** it is retained in the ledger, **Then** its confidence and uncertainty are visible and it is not presented as confirmed fact.

---

### User Story 2 - Track One Event Across Messages (Priority: P2)

An operations developer can inspect how a situation develops across multiple messages instead of seeing a separate event for every message.

**Why this priority**: The ledger is useful only if it represents situations and their lifecycle, not keyword hits.

**Independent Test**: Process a conversation that reports a problem, adds details, resolves it, and later reports recurrence; verify that one event is updated, resolved, and reopened with ordered evidence.

**Acceptance Scenarios**:

1. **Given** an open event and a later message about the same situation, **When** the later message adds detail or action, **Then** the same event is updated and both messages remain linked as evidence.
2. **Given** an open event and a clear later resolution, **When** the resolution is observed, **Then** the event becomes resolved and the resolving message is identified.
3. **Given** a resolved event and a later clear recurrence of the same situation, **When** the recurrence is observed, **Then** the same event is reopened and its prior resolved history remains inspectable.
4. **Given** similar messages in unrelated chats, topics, subjects, or time periods, **When** they are observed, **Then** they are not merged without evidence that they concern the same situation.

---

### User Story 3 - Replay a Short Historical Period (Priority: P3)

An operations developer can select one past date or a short date range and rebuild the event sequence from existing Telegram history for inspection without touching the whole archive or contacting Telegram.

**Why this priority**: Bounded replay makes the observer testable against real historical communication before wider use.

**Independent Test**: Replay a selected fixture period, inspect events and evidence, run the same replay again, and compare it with live observation of the same ordered messages.

**Acceptance Scenarios**:

1. **Given** a selected past date or range of no more than seven consecutive calendar days, **When** replay runs, **Then** eligible messages are processed by message time with a stable tie-breaker and the resulting lifecycle is inspectable.
2. **Given** identical messages processed live and by replay from an empty ledger, **When** both runs complete, **Then** they produce equivalent events, lifecycle transitions, confidence, and evidence links.
3. **Given** the same replay is run twice against the same ledger, **When** the second run completes, **Then** it creates no duplicate event, evidence link, or lifecycle transition.
4. **Given** a requested range longer than seven days or no explicit date boundary, **When** replay is requested, **Then** it is rejected before processing any messages.
5. **Given** replay is running, **When** it processes any message or failure, **Then** it sends nothing to Telegram and makes no change to existing TRIS operational records.

### Edge Cases

- Messages with no usable text or caption, Telegram service messages, and bot-authored messages create no event unless a future explicitly scoped rule supports them.
- Duplicate deliveries of the same Telegram message are processed once; unchanged retries have no ledger effect.
- An edited message is reconsidered only when its stored revision changed; reconsideration updates the existing interpretation without duplicating evidence or history.
- Messages with identical wording but different Telegram message identities remain distinct evidence items.
- Missing author, topic, attachment, or optional operational context does not invent facts; available source identity and data-quality limitations remain visible.
- A vague acknowledgement such as “probably done” may add evidence but does not resolve an event without sufficient confidence.
- A clear resolution that precedes the initial problem in storage but follows it by Telegram time is applied in Telegram-time order.
- An operational question becomes “unanswered” only after the defined observation interval has elapsed without a meaningful response; replay applies the same interval relative to message time.
- Failure while processing one message is recorded for inspection and does not falsely mark it complete; retry remains safe.
- Read-only operational context may corroborate interpretation but never creates an event without at least one source Telegram message.

## Requirements *(mandatory)*

### Scope

This stage begins with Telegram messages already accepted and stored by TRIS work-message ingestion and ends with an inspectable operational event ledger. It adds silent observation, event correlation, lifecycle transitions, evidence, and bounded replay. Existing ingestion, Telegram storage, queue infrastructure, assistant request handling, operational context, and digest behavior are reused rather than replaced.

Morning and evening summaries, conversational Q&A, Telegram replies, autonomous actions, task creation, schedule or apartment changes, long-term AI memory, fine-tuning, and unrelated refactoring are outside scope.

### Functional Requirements

- **FR-001**: The system MUST observe eligible work Telegram messages after existing ingestion without changing ingestion behavior.
- **FR-002**: The observer MUST remain silent: it MUST NOT send Telegram messages, reactions, notifications, or callbacks in live or replay processing.
- **FR-003**: The observer MUST make an explicit selective decision for each eligible message: create an event, update an event, change an event lifecycle, attach evidence only, or create no event.
- **FR-004**: Ordinary, social, logistical-noise, bot-authored, service, empty, and insufficiently meaningful messages MUST create no operational event.
- **FR-005**: A recorded event MUST use one or more of these types: problem, risk, request, commitment, action, resolution, delay, unanswered operational question, quality issue, or meaningful positive contribution.
- **FR-006**: Each event MUST expose a concise factual summary, current lifecycle state, event type, confidence level, uncertainty notes when applicable, first-observed time, last-updated time, and available work-chat/topic context.
- **FR-007**: Every event MUST link to at least one stored Telegram message, and every create, material update, resolution, or reopening MUST identify its supporting source message or messages.
- **FR-008**: Multiple messages concerning the same operational situation MUST be correlated into one event when supported by their chat/topic context, subject, participants, timing, and message content.
- **FR-009**: Messages MUST NOT be merged merely because they share keywords; ambiguous correlation MUST remain separate or explicitly uncertain.
- **FR-010**: Event lifecycle MUST support at least open, resolved, and reopened states, with an ordered history of material transitions.
- **FR-011**: A clear later resolution MUST resolve the relevant open event; an ambiguous possible resolution MUST preserve uncertainty and MUST NOT be represented as confirmed resolution.
- **FR-012**: A clear recurrence of the same situation MUST reopen the resolved event without erasing its earlier lifecycle or evidence.
- **FR-013**: An operational question MUST be eligible for an unanswered-question event only after four elapsed hours without a meaningful response; live and replay processing MUST apply the same rule.
- **FR-014**: Reprocessing the same unchanged source message MUST NOT create duplicate events, evidence links, decisions, or lifecycle transitions.
- **FR-015**: A changed stored message revision MUST be safely reconsidered while preserving one source identity and an auditable current event outcome.
- **FR-016**: Observation MAY use existing operational context as read-only corroboration, but MUST NOT alter existing TRIS operational records or treat non-Telegram context as sole event evidence.
- **FR-017**: Developers MUST be able to inspect events, lifecycle history, confidence, uncertainty, source evidence, processing failures, and the reason available for a no-event decision, without exposing raw Telegram payloads by default.
- **FR-018**: Replay MUST require an explicit past date or inclusive date range of at most seven consecutive calendar days and MUST reject unbounded or larger requests before processing.
- **FR-019**: Replay MUST use the same eligibility, interpretation, correlation, lifecycle, uncertainty, and idempotency rules as live observation.
- **FR-020**: Replay MUST process messages by Telegram send time, followed by a stable source-message tie-breaker, so repeated runs are deterministic.
- **FR-021**: Replay MUST report its selected boundaries, counts of examined/no-event/created/updated/resolved/reopened/failed messages, and identifiers for resulting events.
- **FR-022**: A message processing failure MUST remain retryable and visible without causing a false successful decision or partial duplicate ledger changes.

### Key Entities

- **Operational Event**: A meaningful situation inferred from work communication; carries type, factual summary, lifecycle state, confidence, uncertainty, work context, and observation times.
- **Event Evidence**: A traceable link from an event or lifecycle change to a stored Telegram message, including the message's role such as report, detail, action, resolution, or recurrence.
- **Event Lifecycle Entry**: An ordered, immutable record of a material event transition and the evidence supporting it.
- **Observation Decision**: The idempotent outcome for a source message revision, including no-event and failed outcomes needed for replay safety and inspection.
- **Replay Run**: A bounded developer-requested historical observation with date limits, progress/result counts, and failures; it does not own separate interpretation rules.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the acceptance dataset, 100% of confirmed events and lifecycle transitions link to at least one valid source Telegram message.
- **SC-002**: Reprocessing any unchanged acceptance dataset, including a second replay, produces zero duplicate events, evidence links, or lifecycle transitions.
- **SC-003**: Live observation and replay of the same ordered acceptance dataset produce 100% equivalent event identities, current states, transition sequences, confidence levels, and evidence sets.
- **SC-004**: At least 80% of all eligible messages in the representative acceptance dataset produce no event; at least 95% of reviewed ordinary/non-operational messages and 100% of explicitly defined ordinary-message scenarios produce no event.
- **SC-005**: At least 90% of reviewed meaningful situations in the acceptance dataset are represented by the expected event type and lifecycle, with no unrelated situations merged.
- **SC-006**: 100% of uncertain acceptance cases remain visibly uncertain and none is shown as a confirmed resolution without confirming evidence.
- **SC-007**: Across all live and replay acceptance scenarios, the number of outbound Telegram actions is zero and the number of changes to existing TRIS operational records is zero.
- **SC-008**: A developer can select an allowed replay period and locate each resulting event's lifecycle and source evidence within five minutes using the inspection output.
- **SC-009**: Every replay request without boundaries or longer than seven days is rejected before examining any historical message.

## Assumptions

- Eligible messages are those already accepted by the existing work Telegram ingestion and stored in the existing analytics data source; private-assistant conversations are outside this stage.
- Existing Telegram chat, topic, user, message, attachment, queue, and operational-context capabilities remain authoritative and are not rebuilt.
- Telegram send time is the business chronology; the stored source-message identity provides deterministic ordering when timestamps match.
- Four elapsed hours is the initial unanswered-question interval and can be reviewed in a later product stage without changing the event model.
- Low-confidence text that is not useful enough to record may result in a no-event decision; if an event is recorded despite uncertainty, that uncertainty is retained explicitly.
- Validation uses a human-reviewed dataset containing ordinary chat, each event type, multi-message correlation, uncertain cases, resolution, reopening, duplicates, edits, failures, and replay boundaries.
