# Feature Specification: TRIS Assistant Evening Intelligence

**Feature Branch**: `not-created (no before-specify hook configured)`

**Created**: 2026-09-16

**Status**: Draft

**Input**: Read-only evening management preview for a selected day, based on the existing operational event ledger and supporting TRIS context.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Preview the Day's Operational Picture (Priority: P1)

A manager or developer selects a day and receives a concise preview of the important operational events from that day, separated by current outcome and management relevance.

**Why this priority**: A reliable daily operational picture is the feature's primary value.

**Independent Test**: Generate a preview for a reviewed historical day containing important, trivial, resolved, unresolved, and uncertain ledger events, then compare every included statement and omission with the reviewed ledger.

**Acceptance Scenarios**:

1. **Given** a selected day with important events, **When** the preview is generated, **Then** it presents the important situations in management-priority order rather than reporting raw Telegram activity or message counts.
2. **Given** events resolved during the day and events still unresolved at the end of the day, **When** the preview is generated, **Then** their outcomes are clearly distinguished.
3. **Given** only trivial or low-value events in a possible section, **When** the preview is generated, **Then** those events are omitted and the empty section is not shown.
4. **Given** an event with uncertain interpretation, **When** it is included, **Then** the wording preserves that uncertainty and does not present the event as confirmed fact.

---

### User Story 2 - Trace Summary Items to Evidence (Priority: P2)

A reviewer can inspect any factual item in the preview and identify the operational event and source evidence that supports it.

**Why this priority**: Management summaries must remain auditable and must not invent conclusions.

**Independent Test**: Select every factual item in a historical preview and verify its event reference, relevant lifecycle state or transition, and underlying message evidence.

**Acceptance Scenarios**:

1. **Given** any factual preview item, **When** a reviewer inspects its references, **Then** at least one ledger event and its supporting evidence can be identified.
2. **Given** supporting TRIS context that corroborates an event, **When** it influences the wording, **Then** the context remains read-only and the resulting claim is still anchored to an evidenced ledger event.
3. **Given** no ledger evidence for a possible conclusion, **When** the preview is generated, **Then** that conclusion is not included.

---

### User Story 3 - Identify Tomorrow's Attention (Priority: P3)

A manager can see which unresolved problems, quality issues, risks, delays, or unanswered operational questions merit follow-up on the next work day, without the system taking action.

**Why this priority**: The preview should support management focus while remaining advisory and read-only.

**Independent Test**: Generate a preview for a reviewed day with mixed event types and confirm that follow-up attention is derived only from unresolved or materially recurring evidenced events.

**Acceptance Scenarios**:

1. **Given** unresolved high-importance events at the selected day's end, **When** the preview is generated, **Then** they appear as tomorrow-attention candidates with their uncertainty preserved.
2. **Given** repeated or related issues supported by multiple events or lifecycle evidence, **When** the preview is generated, **Then** the pattern may be surfaced without claiming a broader trend than the evidence supports.
3. **Given** a clearly evidenced meaningful positive contribution, **When** it is operationally relevant, **Then** it may be included without employee scoring, ranking, or generic praise.
4. **Given** the preview is generated, **When** processing completes, **Then** no Telegram message, operational action, task, schedule change, apartment change, or source-record mutation occurs.

### Edge Cases

- A selected day with no qualifying events produces a clear “no material events” preview rather than empty headings or invented content.
- Events created earlier but materially updated, resolved, or reopened on the selected day may be included; unrelated historical backlog is excluded.
- An event opened and resolved on the same day is reported as resolved, with the day's progression retained in its traceability.
- A reopened event is not described as resolved merely because an earlier resolution exists.
- Conflicting evidence or low confidence is stated explicitly and does not become a definitive management conclusion.
- Similar events are described as repeated or related only when the ledger evidence supports that relationship; shared broad wording alone is insufficient.
- An unanswered operational question that is later answered before the day boundary is not reported as unanswered; an event still marked unanswered at the boundary may be surfaced with its recorded confidence.
- Multiple event types describing one situation are consolidated for readability without losing their separate event references or lifecycle outcomes.
- Missing optional supporting context does not prevent a ledger-based preview and does not trigger inference from absent data.
- Historical replay data is treated the same as equivalent ledger data from normal observation.

## Requirements *(mandatory)*

### Scope

This feature begins with a selected calendar day and the existing Spec 001 operational event ledger. It produces a concise, inspectable management preview based on events and lifecycle activity relevant to that day, optionally corroborated by existing read-only TRIS context. It reuses the existing ledger, evidence, confidence, lifecycle, replay, analytics, and operational-context capabilities without redesigning them.

Morning planning or summaries, conversational Q&A, autonomous actions, task creation, schedule or apartment changes, employee scoring or ranking, long-term memory, Telegram auto-messaging, ledger redesign, ingestion changes, and unrelated refactoring are outside scope.

### Functional Requirements

- **FR-001**: The user MUST be able to request an evening intelligence preview for one explicit calendar day, including a past day represented by replay-generated ledger data.
- **FR-002**: The preview MUST summarize operational events and their lifecycle activity, not raw Telegram messages, message volume, or general chat activity.
- **FR-003**: The preview MUST prioritize material unresolved problems, quality issues, risks, delays, unanswered operational questions, material resolutions, and clearly meaningful positive contributions over lower-value events.
- **FR-004**: Trivial, duplicative, generic, or operationally low-value events MUST be omitted from the preview.
- **FR-005**: The preview MUST distinguish events resolved during the selected day from events unresolved at the selected day's end, including reopened events according to their end-of-day state.
- **FR-006**: The preview MAY include only the sections supported by qualifying content, such as key events, unresolved or requires attention, resolved today, quality issues, risks or delays, positive contributions, and tomorrow or follow-up.
- **FR-007**: Every factual preview item MUST reference one or more existing operational events and MUST remain traceable through those events to their source evidence.
- **FR-008**: Supporting TRIS context MAY clarify or corroborate an evidenced event but MUST NOT be the sole basis for a factual preview item.
- **FR-009**: The preview MUST NOT add facts, causes, outcomes, ownership, urgency, or relationships that are not supported by the referenced ledger events, lifecycle entries, evidence, or corroborating context.
- **FR-010**: Event confidence and uncertainty MUST affect both inclusion priority and wording; uncertain interpretations MUST remain visibly qualified.
- **FR-011**: Related or repeated issues MAY be consolidated or highlighted only when their relationship is supported by specific event subjects, context, or evidence, and all contributing event references MUST remain available.
- **FR-012**: Tomorrow-attention items MUST be advisory summaries derived from unresolved or materially recurring evidenced situations and MUST NOT create tasks or make operational changes.
- **FR-013**: Positive contributions MUST be included only when the ledger identifies a specific, meaningful operational contribution; generic thanks, praise, or reactions MUST NOT appear.
- **FR-014**: The preview MUST represent the ledger state as of the end of the selected day and MUST include earlier-created events only when they had material lifecycle activity or remained directly relevant during that day.
- **FR-015**: If no qualifying events exist, the preview MUST state that no material events were identified and MUST NOT force empty sections.
- **FR-016**: Preview generation MUST be read-only and MUST NOT modify the event ledger, source Telegram analytics, existing TRIS operational records, or any external system.
- **FR-017**: Preview generation MUST NOT send any Telegram message, notification, reaction, or other external communication.
- **FR-018**: Reviewers MUST be able to inspect the selected date, included event references, event type, end-of-day status, confidence, and supporting evidence for each factual preview item.

### Key Entities

- **Evening Intelligence Preview**: A transient, read-only management summary for one selected day, containing only supported non-empty sections and references to its source events.
- **Summary Item**: A concise factual or advisory statement derived from one or more operational events, carrying their outcome, confidence or uncertainty, and traceability references.
- **Operational Event**: The existing Spec 001 event, including its type, lifecycle, confidence, uncertainty, timing, and evidence.
- **Supporting Context**: Existing read-only TRIS information that may corroborate an event but cannot independently create a summary claim.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In reviewed historical-day previews, 100% of factual items link to at least one valid operational event and its source evidence.
- **SC-002**: In the acceptance dataset, zero preview statements contain an unsupported fact, definitive conclusion, cause, owner, outcome, or relationship.
- **SC-003**: At least 90% of human-reviewed operationally important events are represented in the appropriate preview section, while at least 95% of reviewed trivial or low-value events are omitted.
- **SC-004**: 100% of resolved, unresolved, and reopened acceptance cases are described according to their correct end-of-day lifecycle state.
- **SC-005**: 100% of uncertain acceptance cases retain visibly qualified wording, and none is elevated to a confirmed fact solely because it was included in the preview.
- **SC-006**: 100% of surfaced repeated or related patterns cite all contributing events, with zero patterns inferred solely from broad wording or raw message volume.
- **SC-007**: A manager can identify the day's key events and tomorrow-attention items from a representative preview in under three minutes.
- **SC-008**: Across all preview scenarios, the number of Telegram outputs, external communications, created tasks, operational changes, ledger mutations, and source-record changes is zero.
- **SC-009**: For a day with no qualifying events, the output contains one clear no-material-events result and zero empty sections.

## Assumptions

- The selected calendar day uses the same business timezone and event chronology already established for the operational ledger.
- The ledger produced by Spec 001 is authoritative for event identity, lifecycle, confidence, uncertainty, and message evidence.
- An event is relevant to the selected day when it is first observed, materially updated, resolved, reopened, or remains directly active with evidenced relevance during that day; unrelated backlog is not included.
- The preview is transient for this stage and is inspected on demand rather than stored, scheduled, or delivered.
- Validation uses human-reviewed historical days from Spec 001 replay containing resolved, unresolved, uncertain, quality, risk or delay, unanswered-question, repeated-issue, positive-contribution, trivial-event, and empty-section cases.
