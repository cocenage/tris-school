# Data Model: TRIS Assistant Evening Intelligence

Spec 002 adds no persistent entities or schema. The following are transient read models built from the existing Spec 001 ledger.

## Existing source entities

### Operational Event

Existing `telegram_operational_events` row. The preview uses `event_key`, `primary_type`, `summary`, chat/topic relationships, and the evidence relationship. Current aggregate status/types/confidence are not trusted as historical snapshots when later evidence exists.

### Event Evidence

Existing `telegram_operational_event_evidence` row. Only `is_current_revision = true` rows participate. Ordered `occurred_at` plus id provides deterministic lifecycle chronology. `status_after`, `transition`, `role`, `confidence`, and `uncertainty` support the as-of-day projection.

### Observation

Existing `telegram_operational_observations` row attached to evidence. Its `reason_code`, `evaluation_kind`, and source message relationship provide the interpretation type and traceability without reclassifying raw messages.

## Transient read entities

### Evening Intelligence Preview

| Field | Type | Rules |
|---|---|---|
| `date` | date string | Required explicit selected date in application timezone |
| `timezone` | string | Existing application timezone |
| `sections` | list of Preview Section | Non-empty sections only |
| `events_considered` | integer | Distinct ledger events with current evidence on selected date |
| `events_included` | integer | Distinct events referenced by output items |
| `events_omitted` | integer | Considered minus included |
| `no_material_events` | boolean | True only when no item qualifies |
| `data_quality` | object | Ledger availability or projection limitations; never a factual event claim |
| `mode` | object | Always read-only with zero Telegram actions and zero mutations |

### Preview Section

| Field | Type | Rules |
|---|---|---|
| `key` | enum | `key_events`, `attention`, `resolved`, `quality`, `risks_delays`, `positive`, `tomorrow` |
| `label` | string | Human-readable heading |
| `items` | list of Summary Item | Must contain at least one item; otherwise section is absent |

### Summary Item

| Field | Type | Rules |
|---|---|---|
| `event_key` | string | Existing stable ledger event key |
| `summary` | string | Existing event summary, normalized only for safe compact presentation |
| `types` | list of enum | Derived as of selected day from stable primary type and eligible observation reason codes |
| `status` | enum | `open`, `reopened`, or `resolved` as of selected day end; dismissed items are excluded |
| `confidence` | enum | `high`, `medium`, or `low`; weakest eligible evidence certainty wins |
| `uncertainty` | nullable string | Combined unique uncertainty supported by eligible evidence |
| `repeated` | boolean | True only for multiple report/recurrence evidence or reopening in the same event |
| `latest_activity_at` | timestamp | Latest eligible evidence at or before day end |
| `evidence` | list of Evidence Reference | At least one reference required |

### Evidence Reference

| Field | Type | Rules |
|---|---|---|
| `local_message_id` | integer | Existing analytics message primary key |
| `telegram_message_id` | string | Existing source message identity; no payload included |
| `role` | string | Existing evidence role |
| `transition` | string | Existing lifecycle transition |
| `occurred_at` | timestamp | Must be at or before selected day end |

## As-of-day projection rules

1. Candidate events have at least one current evidence row inside the selected day.
2. Projection evidence contains all current evidence for that event up to selected day end, ordered by occurrence time and id.
3. End-of-day status equals the last projection evidence `status_after`.
4. `open` and `reopened` are unresolved. `resolved` is resolved. `dismissed` is excluded.
5. Types are mapped from eligible observation reason codes; `unanswered_question_matured` adds `unanswered_question`. The stable `primary_type` remains the base type.
6. Confidence is the weakest eligible evidence value in order `low`, `medium`, `high`; missing certainty cannot upgrade an item.
7. Uncertainty is the unique non-empty eligible evidence uncertainty text, compacted for presentation.
8. Every included item has at least one evidence reference and every section item points to an existing event.

## State transitions

The feature creates no state transitions. It reads existing lifecycle entries and projects their state at the selected day boundary.
