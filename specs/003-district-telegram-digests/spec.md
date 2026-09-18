# Feature Specification: District Telegram Digests

**Feature Branch**: `not-created (no before-specify hook configured)`

**Created**: 2026-09-18

**Status**: Draft

**Input**: Human-readable evening intelligence and district-specific evening/morning duty-topic delivery using existing TRIS Telegram, ledger, weather, and mobility capabilities.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read a Human Evening Summary (Priority: P1)

A manager previews one district's evening intelligence as a short operational summary that highlights what needs attention, quality issues, risks, meaningful resolutions, and next-day follow-up without exposing internal ledger fields.

**Why this priority**: The existing preview is evidence-backed but reads like a debug report and duplicates events.

**Independent Test**: Generate human and technical previews for a reviewed historical district/day; confirm the human version is concise and free of internal fields while every technical field remains available in the machine-readable version.

**Acceptance Scenarios**:

1. **Given** a representative day with material events, **When** the human preview is generated, **Then** each event appears at most once, long source wording is shortened, and only action-relevant sections are shown.
2. **Given** event keys, message/evidence identifiers, confidence, status, transitions, and timestamps, **When** outputs are generated, **Then** those fields are absent from human text and retained in technical output.
3. **Given** low-confidence noise or an isolated generic resolution such as “done”, **When** the human preview is generated, **Then** it does not occupy a summary item without enough operational context.
4. **Given** no material events, **When** preview is generated, **Then** it remains short and reports no material events without empty sections.

---

### User Story 2 - Deliver Evening Intelligence to District Duty Topics (Priority: P2)

An operator can preview or manually send a separate evening summary for each configured district to that district's duty topic, using the existing Telegram sender and an explicit district route.

**Why this priority**: District teams need relevant summaries in one duty topic rather than messages in apartment topics.

**Independent Test**: Configure two district routes in a test environment, dry-run both, verify district event isolation and destinations, then simulate sending one non-empty and one empty summary.

**Acceptance Scenarios**:

1. **Given** an explicit district-to-forum-to-duty-topic route, **When** evening preview or dry-run is requested, **Then** only ledger events from that district forum are summarized and the intended duty destination is reported without sending.
2. **Given** delivery is disabled, **When** a real-send path is requested, **Then** no Telegram request occurs and the command fails safely with a clear reason.
3. **Given** delivery is enabled and a district has material events, **When** manual send is requested, **Then** exactly one message is sent through the existing Telegram sender to that district's duty topic.
4. **Given** a district has no material events, **When** delivery runs, **Then** no Telegram message is sent for that district.
5. **Given** scheduled execution while the delivery flag remains off, **When** the schedule invokes the command, **Then** no external delivery occurs.

---

### User Story 3 - Receive Regional Morning Digests (Priority: P3)

Each configured district receives the established morning digest in the same duty topic, with weather for its configured location and only mobility items whose known geography is relevant to that district; city-wide or geographically unknown items remain shared without invented attribution.

**Why this priority**: Morning digests already exist, but their single Milan point and undifferentiated destinations reduce local usefulness.

**Independent Test**: Build morning dry-runs for two configured districts with distinct weather coordinates and mixed district/city-wide mobility fixtures; verify different weather requests, correct filtering, shared city-wide items, and no sends.

**Acceptance Scenarios**:

1. **Given** two districts with different configured locations, **When** their morning digests are built, **Then** weather is requested for each district's own location and the district name is visible.
2. **Given** a mobility item reliably tagged for one district, **When** digests are built, **Then** it appears only in that district's digest.
3. **Given** a city-wide mobility item or an item without reliable district geography, **When** digests are built, **Then** it may appear in all district digests without being assigned to a guessed district.
4. **Given** weather is unavailable for one district, **When** its digest is built, **Then** the digest remains usable, clearly marks weather unavailable, and still includes relevant restrictions and the normal greeting/closing.
5. **Given** a configured district route, **When** morning delivery runs, **Then** it targets the same duty topic used by that district's evening summary.

### Edge Cases

- A route missing a district key, source forum, duty topic, or valid coordinates is rejected from delivery and identified in preview diagnostics.
- A configured forum or duty topic that cannot be found in local analytics metadata is reported without inventing a replacement.
- An event with evidence from more than one day is included according to its selected-day lifecycle but remains assigned to its source district forum.
- Similar events in different district forums are never merged into one district summary.
- One event qualifying for several sections appears once in the highest-priority human section; technical output retains all types.
- Human shortening never introduces a new owner, location, cause, resolution, or required action.
- A Telegram transport failure is reported as a failed district send and is not retried through a second sender.
- Missing weather must not suppress mobility content, and missing mobility data must not suppress weather.
- Existing unrelated Telegram notifications and apartment topics are never selected as district digest destinations.

## Requirements *(mandatory)*

### Scope

This feature improves the existing evening intelligence presentation, adds protected district duty-topic delivery for evening intelligence, and regionalizes the existing morning weather/mobility digest. It reuses the operational event ledger, Telegram analytics, existing sender, existing mobility records, and current scheduler conventions.

It does not redesign the ledger or ingestion, change assistant replies or webhook security, alter apartment access, add new AI behavior, deploy, enable production delivery, or send a real Telegram message during implementation.

### Functional Requirements

- **FR-001**: Human evening output MUST omit event keys, message/evidence identifiers, confidence, status, transitions, and technical timestamps.
- **FR-002**: Technical evening output MUST retain event identity, evidence references, confidence, lifecycle state and transitions, timestamps, uncertainty, and district routing diagnostics.
- **FR-003**: Human evening output MUST use short action-oriented wording, MUST NOT reproduce avoidably long source messages, and MUST NOT invent unsupported facts.
- **FR-004**: Each event MUST appear at most once in human output, and each non-empty section MUST contain no more than seven items.
- **FR-005**: Low-confidence events MUST be omitted from human output unless supported by stronger evidence in the same event; generic standalone resolutions MUST be omitted when the resolved situation is not identifiable.
- **FR-006**: Evening intelligence MUST be buildable for one explicit district and date using only that district forum's existing ledger events.
- **FR-007**: District routing MUST explicitly map a stable district key and label to one source forum, one duty topic, and one weather location; routing MUST be configurable and absent routes MUST NOT be inferred.
- **FR-008**: Evening delivery MUST use the existing Telegram sender, MUST send at most one summary per district/day invocation, and MUST never target apartment topics.
- **FR-009**: Evening delivery MUST provide preview/dry-run and manual-send paths; actual delivery MUST be blocked by a feature flag that defaults to off.
- **FR-010**: Empty evening summaries MUST NOT be sent.
- **FR-011**: Scheduled evening execution MAY be registered only through the existing scheduler and MUST remain externally inert while the feature flag is off.
- **FR-012**: Morning digest MUST retain greeting, weather, meaningful restrictions/strikes, and a short closing.
- **FR-013**: Morning weather MUST use each district's configured coordinates and MUST degrade to an explicit unavailable state without failing the remaining digest.
- **FR-014**: Mobility items with a reliable district designation MUST be limited to that district; city-wide or geographically unknown items MUST remain shared and MUST NOT be assigned to a guessed district.
- **FR-015**: Morning district delivery MUST use the same configured duty destination as evening delivery and the existing Telegram sender.
- **FR-016**: Existing legacy morning targets MAY remain available as a compatibility path when district routes are not configured; configuring district routes MUST activate regional rendering without changing unrelated notifications.
- **FR-017**: Preview and dry-run operations MUST perform zero Telegram requests and zero database mutations.
- **FR-018**: Implementation and validation MUST NOT enable live observation, production delivery, deployment, or real Telegram sends.

### Key Entities

- **District Route**: Configured district key, human label, source forum identifier, duty-topic identifier, and weather coordinates used consistently by both daily digest flows.
- **District Evening Preview**: Existing evidence-backed ledger summary filtered to one route and rendered in either human or technical form.
- **District Morning Digest**: Existing morning weather/mobility content rendered for one route using regional location and evidence-based mobility scope.
- **Delivery Result**: Per-district outcome describing previewed, skipped-empty, blocked-disabled, sent, or failed without exposing secrets.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Across reviewed historical previews, human output contains zero internal identifiers, technical state labels, confidence values, transitions, or technical timestamps.
- **SC-002**: Technical output retains 100% of the event and evidence fields present before this feature and adds an inspectable district route result.
- **SC-003**: Every included human evening event appears exactly once, every section contains at most seven items, and zero generic context-free resolutions are shown.
- **SC-004**: In district isolation tests, 100% of evening events come from the selected district forum and zero events from another forum are included.
- **SC-005**: For configured non-empty evening digests, exactly one duty-topic send is attempted; for empty, disabled, preview, and dry-run cases, zero sends are attempted.
- **SC-006**: The evening delivery flag is off by default and scheduled execution produces zero Telegram requests under default configuration.
- **SC-007**: Two district morning acceptance cases use distinct configured coordinates and target their matching duty topics.
- **SC-008**: 100% of reliably district-tagged mobility fixtures appear only in matching digests, while 100% of city-wide/unknown fixtures remain shared without fabricated district labels.
- **SC-009**: A weather failure still produces a valid morning digest containing greeting, weather-unavailable notice, applicable mobility content, and closing.
- **SC-010**: Relevant automated tests, changed-file formatting checks, historical preview, district delivery dry-run, two-district morning dry-run, mapping validation, empty-digest validation, and broader regression checks all pass without a real Telegram request.

## Assumptions

- The five intended districts are represented by the existing operational forums, but their external forum and duty-topic identifiers are supplied by deployment configuration rather than inferred from imported titles.
- The same application timezone governs both morning and evening date boundaries.
- The existing event ledger remains authoritative for evening facts and traceability.
- Mobility geography is reliable only when existing source data explicitly supplies a matching configured district; missing geography means shared/city-wide, not guessed.
- The weather provider accepts latitude and longitude and may be safely queried independently for each configured district.
- Local validation may use process-local test routes because production identifiers are not present in the local environment.
