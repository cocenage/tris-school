# Phase 0 Research: TRIS AI Operational Event Ledger

## Decision 1: Preserve the existing ingestion boundary

**Decision**: Dispatch silent observation after successful persistence in both existing live paths: the authorized work webhook using `TelegramUpdateIngestService` and the analytics webhook's existing upsert path.

**Rationale**: Repository inspection found two live persistence paths. Observation needs their stored message identity and must not rebuild either parser or storage path. The observer reapplies work eligibility so private, bot, service, and disallowed analytics messages remain no-event decisions.

**Alternatives considered**: Treating only one webhook as authoritative would silently miss messages if the other remains active. Refactoring the analytics controller through the ingest service is broader than Spec 001. Embedding interpretation inside ingestion would mix persistence with event semantics and make replay harder.

## Decision 2: Do not reuse assistant request rows as events

**Decision**: Keep `telegram_assistant_requests` and `telegram_assistant_request_messages` unchanged; add a separate, analytics-only ledger.

**Rationale**: Existing request rows represent activated conversations, store bot reply IDs and clarification states, and are handled by a service that sends Telegram messages and staff notifications. Their root-message uniqueness and direction field cannot represent no-event decisions, edited revisions, uncertain correlation, or evidence-backed resolve/reopen history safely.

**Alternatives considered**: Adding many nullable event fields and new statuses to assistant requests looked smaller by table count but would couple silent observation to outbound conversation behavior and risk existing production semantics.

## Decision 3: Use three ledger records in one migration

**Decision**: Add operational events, per-message-revision observations, and event evidence/transition links; keep replay runs as transient command results.

**Rationale**: The event row gives fast current state, observation rows make every revision/no-event/failure idempotent and inspectable, and evidence rows provide many-message traceability plus immutable lifecycle history. Persisting replay runs is unnecessary for Spec 001 because the command result supplies boundaries and counts.

**Alternatives considered**: One event table with JSON history cannot enforce evidence uniqueness or safe retries. A fourth lifecycle table duplicates information already carried by evidence transitions. A replay-runs table adds persistence without a required user outcome.

## Decision 4: Add a narrow operational interpreter; add no AI provider

**Decision**: Add a dedicated side-effect-free `TelegramOperationalInterpreter` with a strict structured result. It consumes the existing `TelegramAssistantClassifier` as one category signal and follows the conservative problem/positive/question/response behavior proven by digest/context tests. No network AI provider is introduced in this stage.

**Rationale**: Repository inspection found no AI client/provider contract. Adding credentials, network failure modes, prompt storage, cost controls, and non-deterministic replay would expand the feature substantially. A conservative deterministic first stage satisfies silence, idempotency, uncertainty, and replay parity and establishes the ledger that later model-backed interpretation can target.

**Alternatives considered**: Expanding `TelegramAssistantClassifier` to own ten event types would mix activated-request semantics with passive operational interpretation. Refactoring digest/context builders onto the new interpreter risks working summaries and violates minimal scope. Calling an external model is not supported by current architecture or Spec 001.

## Decision 5: Keep digest and operational context read-only and unchanged

**Decision**: Use `TelegramForumDigestBuilder` and the Telegram portion of `OperationalContextBuilder` as behavioral references only. Do not call or modify them in the initial observer.

**Rationale**: Both summarize aggregate signals and intentionally avoid writes. Per-message event creation does not require their broader data, and Spec 001 only permits operational context as optional corroboration.

**Alternatives considered**: Calling `OperationalContextBuilder` for every message would read unrelated primary operational data and add cost. Converting either builder into the event engine would change existing morning/evening responsibilities explicitly excluded by the spec.

## Decision 6: Reuse the existing queue pattern without scheduler changes

**Decision**: Add one `ShouldQueue` job modeled after `ProcessTelegramInstructionAutoReply`. The same job accepts normal observation and delayed-unanswered modes.

**Rationale**: Webhook latency stays bounded, queue retry behavior is familiar, and a delayed invocation at four hours satisfies unanswered-question timing without adding a scheduler responsibility or a second job class.

**Alternatives considered**: Synchronous webhook interpretation increases delivery latency. A scheduled archive scan conflicts with bounded processing and existing scheduler scope. A separate unanswered job adds surface without distinct behavior.

## Decision 7: Make message revisions and writes transactionally idempotent

**Decision**: Identify work by source message, relevant content revision hash, and evaluation mode. Commit a completed observation together with event/evidence mutations in one analytics transaction; record failures separately and allow the same key to retry. Revision parsing tolerates `raw` stored as either an array or legacy JSON text.

**Rationale**: Telegram retries, queue retries, replay reruns, and edits all become safe. Completed work is returned without mutation; failed work never masquerades as complete.

**Alternatives considered**: Keying only by Telegram message ID would ignore edits. Keying only by content hash could collide across messages. Cache locks alone do not provide durable replay idempotency.

## Decision 8: Correlate conservatively and deterministically

**Decision**: Prefer explicit reply chains, then same chat/topic, compatible type/subject, chronological proximity, and a bounded seven-day candidate horizon. When more than one event remains plausible, preserve uncertainty or create no event instead of guessing.

**Rationale**: This directly supports “several messages, one event” while preventing keyword-only merging. A stable root-derived event key makes independent live/replay results comparable.

**Alternatives considered**: Always using the latest topic event causes false merges. One event per message violates lifecycle requirements. Unbounded semantic matching risks unrelated historical events and archive-scale queries.

## Decision 9: Provide internal CLI contracts only

**Decision**: Add a bounded replay command and a read-only inspection command; add no API endpoint or Filament resource.

**Rationale**: Spec 001 names developers as the inspection actor. Artisan matches existing preview patterns and avoids authorization/UI scope.

**Alternatives considered**: A Filament ledger UI or HTTP API adds policy, UI, and public-contract work not required in the first stage. Replay-only output cannot inspect live ledger state later, so a small read-only command is still needed.

## Decision 10: Use an independent live-observation rollout flag

**Decision**: Add `services.telegram.operational_observer_enabled`, documented in `.env.example` and disabled by default. Replay and read-only inspection remain explicit developer commands and do not depend on the live flag.

**Rationale**: The existing assistant flag controls outbound request behavior and must not be reused for an unrelated silent observer. An independent opt-in permits schema deployment and acceptance validation before production traffic starts generating ledger writes; no `.env` value is changed by implementation.

**Alternatives considered**: Reusing `assistant_enabled` couples unrelated behaviors. Enabling silently by default changes production processing immediately after deployment. Omitting a flag makes staged validation harder in a mature system.

## Validation Risk: Classification quality

The existing deterministic classifier and signal builders do not cover all ten Spec 001 types or semantic correlation. The new interpreter must remain conservative, and implementation is not accepted on synthetic rules alone: the reviewed acceptance corpus must demonstrate SC-004 and SC-005. If it cannot, the implementation phase must stop and report the gap rather than silently add an external AI provider or weaken the criteria.
