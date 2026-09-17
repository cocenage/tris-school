# Data Model: TRIS AI Operational Event Ledger

All records below use the existing `analytics` connection. They reference existing Telegram rows and never duplicate raw updates, message text, chat metadata, users, or attachments.

## Existing Entities Reused

### TelegramMessage

Authoritative source message. Existing fields and relationships remain unchanged; only outbound relationships to observations/evidence are added in code.

Relevant identity and chronology:

- `id`: stable local source identifier and chronological tie-breaker.
- `telegram_chat_id`, `telegram_topic_id`, `telegram_user_id`: existing work context.
- `message_id`: external Telegram identity, unique with the chat.
- `text`, `caption`, `message_type`, `sent_at`, `edited_at`, `raw`: current stored revision inputs.

### TelegramChat / TelegramTopic / TelegramUser / TelegramAttachment

Existing context only. No new copies or mutations are required by the ledger.

## New Entity: TelegramOperationalEvent

Current projection of one operational situation.

| Field | Rules |
|---|---|
| `id` | Internal primary key. |
| `event_key` | Stable logical identity derived from the root source chat/message; unique. |
| `root_message_id` | Required reference to the Telegram message that first created the event. |
| `telegram_chat_id` | Required existing chat reference. |
| `telegram_topic_id` | Nullable existing topic reference. |
| `primary_type` | Required value from the Spec 001 event vocabulary. |
| `types` | Required unique list containing `primary_type`; supports secondary types such as `request` plus `unanswered_question`. |
| `summary` | Required concise factual statement; no invented facts. |
| `status` | `open`, `resolved`, `reopened`, or `dismissed` after a correcting edit removes all current support. |
| `confidence` | `low`, `medium`, or `high`. |
| `uncertainty` | Nullable short explanation; required for low-confidence current state. |
| `subject_key` | Normalized conservative correlation key; not displayed as fact. |
| `first_observed_at` | Telegram time of first current evidence. |
| `last_observed_at` | Telegram time of latest current evidence. |
| `resolved_at` | Nullable; set only for confirmed resolution, cleared on reopening. |
| timestamps | Ledger persistence timestamps. |

Indexes:

- unique `event_key`;
- `(telegram_chat_id, telegram_topic_id, status, last_observed_at)` for bounded candidate lookup;
- `root_message_id`.

Validation:

- An event must always have at least one evidence row in the same committed transaction.
- A confirmed resolution requires evidence with transition `resolved`.
- Low confidence requires non-empty uncertainty.
- `dismissed` is used only for source correction; events are not deleted by replay.

## New Entity: TelegramOperationalObservation

Durable processing decision for one source-message revision and evaluation purpose. It is the idempotency boundary and includes messages that produce no event.

| Field | Rules |
|---|---|
| `id` | Primary key. |
| `telegram_message_id` | Required existing message reference. |
| `source_revision_hash` | Hash of interpretation-relevant stored fields, including edit identity/content. |
| `evaluation_kind` | `message` or `unanswered`. |
| `state` | `processing`, `completed`, or `failed`. |
| `outcome` | Nullable until complete; `no_event`, `created`, `updated`, `evidence`, `resolved`, `reopened`, `dismissed`, or `pending_question`. |
| `reason_code` | Required for completed no-event/pending decisions; safe machine-readable explanation. |
| `confidence` | Nullable `low`, `medium`, or `high`. |
| `uncertainty` | Nullable; required when the decision is retained at low confidence. |
| `unanswered_due_at` | Nullable logical deadline for a pending operational question. |
| `is_current_revision` | Exactly one message-evaluation observation is current for a source message after successful revision processing. |
| `processed_at` | Nullable until a terminal state. |
| `error_code` | Nullable safe error classification; no secrets or raw payload. |
| timestamps | Persistence timestamps. |

Constraints and indexes:

- unique `(telegram_message_id, source_revision_hash, evaluation_kind)`;
- index `(state, processed_at)`, `(telegram_message_id, is_current_revision)`, and `(unanswered_due_at, state)`;
- a completed key is immutable/idempotent;
- a failed key may transition back through processing to completed on retry;
- event/evidence mutations and completed state commit atomically.

## New Entity: TelegramOperationalEventEvidence

Links an observation to an event and doubles as the append-only material lifecycle history.

| Field | Rules |
|---|---|
| `id` | Primary key and stable sequence tie-breaker. |
| `operational_event_id` | Required event reference. |
| `observation_id` | Required observation reference. |
| `role` | `report`, `detail`, `request`, `commitment`, `action`, `resolution`, `recurrence`, `positive`, `question`, or `correction`. |
| `transition` | `created`, `updated`, `evidence`, `resolved`, `reopened`, `dismissed`, or `none`. |
| `status_before` | Nullable event status before this evidence. |
| `status_after` | Required event status after this evidence. |
| `confidence` | Required `low`, `medium`, or `high`. |
| `uncertainty` | Nullable; required for low confidence. |
| `occurred_at` | Source Telegram time used for lifecycle ordering. |
| `is_current_revision` | Whether this interpretation remains current for its source message revision. |
| timestamps | Persistence timestamps. |

Constraints and indexes:

- unique `(operational_event_id, observation_id, role, transition)`;
- index `(operational_event_id, occurred_at, id)` for ordered history;
- prior rows are retained when an edited message produces a correction; the new correction records the superseding outcome.

## Runtime Value: ReplayResult

Not persisted in Spec 001.

- inclusive `from`/`to` boundaries and application timezone;
- counts: examined, no-event, created, updated, evidence, resolved, reopened, dismissed, pending, failed;
- affected stable event keys;
- per-message safe failure identifiers;
- `telegram_actions = 0`.

## Relationships

```text
TelegramChat 1 ── * TelegramOperationalEvent
TelegramTopic 1 ── * TelegramOperationalEvent (optional)
TelegramMessage 1 ── * TelegramOperationalObservation (revisions/modes)
TelegramOperationalObservation 1 ── * TelegramOperationalEventEvidence
TelegramOperationalEvent 1 ── * TelegramOperationalEventEvidence
```

Every event reaches at least one Telegram message through evidence → observation → message.

## Lifecycle

```text
new meaningful message ──created──> open
open ──clear resolution──> resolved
resolved ──clear recurrence──> reopened
reopened ──clear resolution──> resolved
any supported state ──detail/action──> same state with updated projection
any supported state ──correcting edit removes all current support──> dismissed
```

Ambiguous resolution adds uncertain evidence or yields no event; it never enters `resolved`. An edited revision appends a correction observation/evidence record and recalculates the projection without deleting history.

## Correlation Invariants

- Explicit reply linkage wins when the replied-to message already supports an event.
- Automatic candidates remain in the same chat and normally the same topic.
- Compatible subject/type and chronological proximity are all required; shared keywords alone are insufficient.
- Multiple equally plausible candidates produce no guessed merge.
- The first creating source message determines `event_key`, making logical identity deterministic across empty-ledger live and replay runs.
