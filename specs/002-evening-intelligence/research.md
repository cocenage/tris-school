# Research: TRIS Assistant Evening Intelligence

## Decision 1: Use the Spec 001 ledger as the sole Telegram intelligence source

**Decision**: Query `TelegramOperationalEvent` with its current evidence and observations. Do not use raw Telegram messages or `TelegramForumDigestBuilder` to decide summary content.

**Rationale**: Spec 001 already supplies selectivity, correlation, lifecycle, confidence, uncertainty, and evidence. The forum digest instead counts messages and lexical signals, which would recreate a weaker parallel interpretation path.

**Alternatives considered**:

- Extend `TelegramForumDigestBuilder`: rejected because it is topic-volume and keyword based.
- Re-run `TelegramOperationalInterpreter` while summarizing: rejected because the ledger is already the approved interpretation and replay/live parity source.
- Introduce another analytics store: rejected because all required data exists in the current ledger.

## Decision 2: Reconstruct the selected day from evidence, not current event status alone

**Decision**: Select events by evidence occurring on the requested day and derive end-of-day status from ordered current evidence at or before the day boundary.

**Rationale**: An event can be resolved or reopened after the requested historical date. Reading only its present status would leak future lifecycle information into a historical preview.

**Alternatives considered**:

- Use `telegram_operational_events.status`: rejected for historical as-of accuracy.
- Store daily snapshots: rejected because preview-only Spec 002 does not require new persistence.
- Include all open backlog: rejected because the spec excludes unrelated historical backlog.

## Decision 3: Derive as-of-day types and certainty from existing evidence metadata

**Decision**: Use the stable event `primary_type`, observation `reason_code`, unanswered maturation code, and evidence confidence/uncertainty up to day end. Do not rely on the event's current aggregate `types`, confidence, or uncertainty when later evidence exists.

**Rationale**: Observation reason codes already identify the interpretation that produced each evidence row, while evidence stores its certainty. This avoids both schema changes and future-state leakage.

**Alternatives considered**:

- Use current aggregate event fields unchanged: rejected because later evidence can change them.
- Add type snapshots to evidence: rejected as unnecessary for the current deterministic mappings.
- Read and reclassify message text: rejected because it would duplicate observer behavior.

## Decision 4: Add a focused builder, reuse the current formatter and CLI conventions

**Decision**: Add `TelegramEveningIntelligenceBuilder`, a new explicit-date preview command, and one dedicated method on `TelegramDigestFormatter`.

**Rationale**: Existing preview commands already establish date parsing, JSON/human modes, dependency injection, and explicit read-only messaging. A separate builder keeps ledger projection isolated without changing the old forum digest contract.

**Alternatives considered**:

- Put all queries in the command: rejected because lifecycle projection needs focused unit tests.
- Replace `TelegramDigestFormatter::evening()`: rejected because it would change existing forum-preview behavior.
- Add a second formatter service: rejected because the existing formatter is the established deterministic presentation boundary.

## Decision 5: Do not make broad operational context a required input in the first slice

**Decision**: Keep `OperationalContextBuilder` unchanged and do not let its broad context or raw Telegram counters create summary items. Follow its timezone, source-quality, and read-only conventions. Context can be added later only through an explicit event-backed corroboration rule within Spec 002.

**Rationale**: No current reliable key joins a ledger event to arbitrary task, calendar, check, or staff records. Treating broad context as factual support would risk unsupported conclusions, and the feature remains valid with ledger-only evidence.

**Alternatives considered**:

- Always build the full operational context: rejected as unnecessary work and a source of unrelated morning-oriented data.
- Match by free text: rejected because it could invent relationships.
- Copy context data into the ledger: rejected because existing operational records are read-only and no new storage is needed.

## Decision 6: Keep related-issue reporting conservative

**Decision**: Surface repetition only inside one correlated ledger event when evidence shows multiple reports, recurrence, or reopening. Do not group separate events in the initial implementation.

**Rationale**: Spec 001 deliberately hardened correlation against broad-topic merging. Re-grouping events for a summary would reintroduce the same failure mode.

**Alternatives considered**:

- Group by broad event type or chat/topic: rejected as false-pattern prone.
- Group by subject key across events: deferred because a safe cross-event rule is not necessary for the first checkpoint.

## Decision 7: Keep output transient and auditable

**Decision**: Return an in-memory preview contract with event/evidence references and render it to CLI or JSON. Do not save summaries or send them.

**Rationale**: This satisfies historical validation and traceability while guaranteeing read-only behavior.

**Alternatives considered**:

- Persist generated summaries: rejected as out of scope.
- Schedule or deliver to Telegram: rejected explicitly by Spec 002.
- Generate unconstrained prose: rejected because deterministic, evidence-backed output is the safest first stage.
