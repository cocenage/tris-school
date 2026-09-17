# Implementation Plan: TRIS AI Operational Event Ledger

**Branch**: `release/tris-v1` (current checkout; feature identifier `001-operational-event-ledger`) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-operational-event-ledger/spec.md`

## Summary

Add a silent, queue-backed observer after both existing Telegram persistence paths. A small side-effect-free operational interpreter reuses the existing assistant classifier's category signals and the conservative signal behavior already established by digest/context code, while the observer stores only the missing event/observation/evidence records on the existing `analytics` connection and correlates later messages into an evidence-backed lifecycle. A bounded replay command feeds historical messages through the same observer in chronological order; a read-only command exposes the ledger for developers. Existing ingestion, assistant request conversations, Telegram delivery, forum digest, operational context, scheduler, and primary operational records remain unchanged.

## Technical Context

**Language/Version**: PHP 8.3 platform target (`^8.2` supported)

**Primary Dependencies**: Laravel 12, Eloquent, Laravel queues, Carbon; existing `TelegramAssistantClassifier`, stored `TelegramMessage` relationships, and work-chat configuration

**Storage**: Existing SQLite-compatible `analytics` connection for Telegram data; no primary-database writes

**Testing**: Pest 4 with isolated in-memory analytics schemas, queue fakes, HTTP fakes, and command tests

**Target Platform**: Existing TRIS server/worker deployment

**Project Type**: Mature Laravel monolith with webhook ingestion, background jobs, and internal Artisan inspection commands

**Performance Goals**: Keep webhook work to persistence plus queue dispatch; process one live message within the existing short-job envelope; scan replay input in bounded chunks without loading a seven-day range into memory

**Constraints**: Zero Telegram output; no external AI/provider call in this stage; deterministic and idempotent live/replay outcomes; maximum seven-day replay; raw Telegram payload excluded from default inspection; existing operational data is read-only

**Scale/Scope**: Work chats already admitted by `TelegramWorkWebhookController`; one message at a time live and explicitly bounded historical periods; no whole-archive processing

## Constitution Check

*GATE: Passed before Phase 0 and re-checked after Phase 1.*

The project constitution is still an unratified placeholder and defines no enforceable gates. The repository's active instructions and recorded decisions supply the effective gates:

- **PASS — analytics separation**: all new ledger records use the existing `analytics` connection beside Telegram messages.
- **PASS — reuse**: both ingestion paths, stored messages, queue infrastructure, allowed-chat rules, assistant category signals, and preview conventions are reused; assistant conversation tables are not repurposed.
- **PASS — silence**: observer, replay, and inspection dependencies exclude `TelegramBotService` and all outbound transports.
- **PASS — operational data safety**: `OperationalContextBuilder` and existing TRIS records remain read-only and are not required for event creation.
- **PASS — scheduler scope**: the four-hour unanswered check uses a delayed queue job; no scheduler responsibilities change.
- **PASS — minimal scope**: no digest, Q&A, task, schedule, apartment, UI, fine-tuning, or unrelated refactor is included.
- **PASS — verification**: acceptance fixtures cover exact chronology, duplicate delivery, edits, uncertainty, lifecycle, replay parity, and zero outbound calls.

## Project Structure

### Documentation (this feature)

```text
specs/001-operational-event-ledger/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── observer-result.md
│   └── artisan-commands.md
└── tasks.md                 # Created later by $speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   ├── TelegramOperationalReplayCommand.php       # new, bounded write-to-ledger replay
│   └── TelegramOperationalEventsCommand.php       # new, read-only inspection
├── Jobs/
│   └── ProcessTelegramOperationalMessage.php      # new, live/delayed queue entry
├── Models/
│   ├── TelegramMessage.php                        # add ledger relationships only
│   ├── TelegramOperationalEvent.php               # new
│   ├── TelegramOperationalObservation.php         # new
│   └── TelegramOperationalEventEvidence.php       # new
├── Services/Telegram/
│   ├── TelegramAssistantClassifier.php            # existing pure category signal source
│   ├── TelegramOperationalInterpreter.php         # new, conservative structured decision
│   └── TelegramOperationalEventObserver.php       # new, shared live/replay application flow
└── Http/Controllers/
    ├── TelegramWorkWebhookController.php           # dispatch after successful ingestion
    └── TelegramAnalyticsWebhookController.php      # dispatch after successful persistence

config/
└── services.php                                   # independent observer enable flag

.env.example                                       # document the optional rollout flag

database/migrations/
└── *_create_telegram_operational_event_ledger.php # new analytics-only schema

tests/
├── Feature/
│   ├── TelegramOperationalEventObserverTest.php
│   ├── TelegramOperationalReplayCommandTest.php
│   ├── TelegramOperationalEventsCommandTest.php
│   ├── TelegramWorkWebhookTest.php                # extend dispatch/silence coverage
│   └── TelegramAnalyticsWebhookTest.php           # extend dispatch/silence coverage
└── Unit/
    └── TelegramOperationalInterpreterTest.php
```

**Structure Decision**: Keep the feature inside existing Telegram namespaces and the analytics store. Use one pure interpreter behind one observer application service; both the queue job and replay command call that observer. Add only two developer commands: one bounded replay writer and one read-only ledger inspector. Do not modify `TelegramAssistantService`, assistant request tables, `TelegramForumDigestBuilder`, `OperationalContextBuilder`, or scheduler routes.

## Phase 0: Research Conclusions

Research is recorded in [research.md](research.md). All technical-context questions are resolved with no open clarification markers.

## Phase 1: Design

- Data records, constraints, relationships, and lifecycle rules: [data-model.md](data-model.md)
- Observer and CLI contracts: [contracts/](contracts/)
- Acceptance-oriented validation workflow: [quickstart.md](quickstart.md)

### Processing Flow

1. The existing work webhook continues to validate chat eligibility and persist through `TelegramUpdateIngestService`; the analytics webhook continues its existing persistence behavior. Neither path is rebuilt.
2. When live observation is enabled, each successful path dispatches `ProcessTelegramOperationalMessage` with the stored message ID. Duplicate dispatch is safe, and assistant activation/reply behavior remains independent.
3. The job reloads the existing message with chat, topic, user, and attachments, then invokes `TelegramOperationalEventObserver` in normal or delayed-unanswered mode.
4. The observer calculates a stable source-revision fingerprint, reuses a completed decision when present, classifies conservatively, correlates only compatible candidates, and atomically writes the observation, event snapshot, and evidence/transition row.
5. An operational question can create its normal event immediately; the same job class is delayed for four hours to evaluate only the unanswered state. The second evaluation has its own idempotency key.
6. Replay selects only eligible stored work messages inside explicit boundaries, iterates by `sent_at` then `id`, and calls the same observer directly with a replay clock. It observes all messages sharing a timestamp before maturing unanswered deadlines at that timestamp, then matures only deadlines due by the range end.
7. Inspection reads ledger records and projections only; default output omits raw payloads and full message bodies.

### Correlation Rules

- An explicit reply to a message already linked to an event is the strongest correlation signal.
- Otherwise candidates are limited to the same work chat and, when present, the same topic, then matched by compatible operational category/subject and a seven-day candidate horizon.
- A lifecycle-only message such as “fixed” attaches only when exactly one compatible candidate is sufficiently supported; ambiguity produces a no-event/uncertain decision rather than a guessed merge.
- The logical event key derives from the root source chat/message identity, so an empty-ledger live run and replay produce the same external event identity.
- Edited revisions create a new observation, retain earlier history, supersede the earlier current interpretation, and recalculate the event snapshot. An event with no remaining current supporting evidence becomes dismissed rather than silently deleted.

## Post-Design Constitution Check

**PASS**. Phase 1 introduces one coherent analytics migration, three narrowly scoped models, a pure interpreter plus one observer application service, one reusable job, and two internal commands. It adds no external provider, Telegram sender, scheduler entry, main-database mutation, or change to existing summaries. The three ledger records are the minimum needed to keep current event state, per-revision idempotency/no-event decisions, and traceable lifecycle evidence without overloading the outbound assistant request schema.
