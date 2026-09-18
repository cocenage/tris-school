# Tasks: District Telegram Digests

**Input**: Design documents from `specs/003-district-telegram-digests/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Required by the feature Definition of Done. Work is grouped into bounded execution packets and validated after each story.

## Phase 1: Baseline and Shared Routing

**Purpose**: Preserve the reproduced baseline and establish one route contract shared by morning and evening.

- [X] T001 Record the reproduced baseline and decisions in `specs/003-district-telegram-digests/research.md`
- [X] T002 Add off-by-default evening delivery and five explicit district route entries to `config/services.php` and documented placeholders to `.env.example`
- [X] T003 Add route validation/selection tests in `tests/Unit/TelegramDistrictRouteRegistryTest.php`
- [X] T004 Implement the thin config-backed registry in `app/Services/Telegram/TelegramDistrictRouteRegistry.php`

**Checkpoint**: Valid routes expose `district -> chat_id -> duty_thread_id -> coordinates`; incomplete routes cannot deliver.

---

## Phase 2: User Story 1 — Human Evening Summary (Priority: P1) 🎯 MVP / Packet A

**Goal**: Keep complete technical output while making human output concise, unique, and free of debug fields.

**Independent Test**: Historical preview for 2026-07-23 plus automated assertions for hidden debug fields, low-confidence suppression, generic resolution suppression, unique section membership, and retained JSON evidence.

- [X] T005 [US1] Add failing projection/presentation regression cases to `tests/Unit/TelegramEveningIntelligenceBuilderTest.php`, `tests/Unit/TelegramDigestFormatterTest.php`, and `tests/Feature/TelegramEveningIntelligencePreviewCommandTest.php`
- [X] T006 [US1] Add optional district filtering, complete technical events, unique section assignment, and human eligibility to `app/Services/Telegram/TelegramEveningIntelligenceBuilder.php`
- [X] T007 [US1] Render concise human-only bullets and district heading while retaining technical JSON in `app/Services/Telegram/TelegramDigestFormatter.php` and `app/Console/Commands/TelegramEveningIntelligencePreviewCommand.php`
- [X] T008 [US1] Run the Packet A targeted tests and historical human/JSON preview; perform at most one bounded repair

**Checkpoint**: Human output has no internal noise or duplicates; JSON remains evidence-complete.

---

## Phase 3: User Story 2 — Safe District Evening Delivery (Priority: P2) / Packet B

**Goal**: Dry-run or manually send one non-empty district ledger summary only to its configured duty topic.

**Independent Test**: Two configured routes prove forum isolation; disabled, dry-run and empty cases make zero sends; enabled non-empty case makes one mocked sender call.

- [X] T009 [US2] Add command safety and routing tests in `tests/Feature/TelegramEveningIntelligenceSendCommandTest.php`
- [X] T010 [US2] Implement `app/Console/Commands/TelegramEveningIntelligenceSendCommand.php` using the existing builder, formatter, registry, and `TelegramBotService`
- [X] T011 [US2] Register the off-by-default scheduled command in `routes/console.php` and validate Packet B mapping/dry-run/empty/flag/send scenarios

**Checkpoint**: District evening delivery is operational through the manual path but inert by default and never sends empty summaries.

---

## Phase 4: User Story 3 — Regional Morning Digest (Priority: P3) / Packet C

**Goal**: Preserve the morning concept while using district coordinates, evidence-based mobility scope, shared duty routes, and the existing sender.

**Independent Test**: Two districts use distinct coordinates and destinations; exact district mobility stays local; M-line/city-wide/unknown items remain shared; weather failure degrades gracefully.

- [X] T012 [US3] Add coordinate, fallback, and diagnostics tests in `tests/Unit/MilanWeatherServiceTest.php`
- [X] T013 [US3] Parameterize `app/Services/Weather/MilanWeatherService.php` by validated coordinates/timezone while preserving its default Milan behavior
- [X] T014 [US3] Add two-district, mobility-scope, legacy-compatibility, dry-run, and existing-sender tests to `tests/Feature/MobilityDigestCommandTest.php`
- [X] T015 [US3] Regionalize `app/Console/Commands/MobilityDigestCommand.php`, keep greeting/weather/restrictions/closing, reuse shared routes and `TelegramBotService`, and remove its parallel HTTP sender path
- [X] T016 [US3] Run Packet C tests and two-district dry-run verification; perform at most one bounded repair

**Checkpoint**: Morning district digests use matching route/location, do not invent geography, and preserve legacy fallback when no routes exist.

---

## Phase 5: Deterministic Closure / Packet D

- [X] T017 Run all changed-scope tests plus the broader relevant Telegram/mobility suite and record deterministic PASS/FAIL
- [X] T018 Run scoped Pint on changed PHP files and `git diff --check`
- [X] T019 Validate historical human and JSON evening output, one district evening dry-run, empty skip, two regional morning previews, route mapping, default-off flag, and zero real Telegram requests against `specs/003-district-telegram-digests/quickstart.md`
- [X] T020 Confirm no migrations, production sends, deployment, live-observer changes, or unrelated refactors; mark completed tasks in `specs/003-district-telegram-digests/tasks.md`

**Closure evidence (2026-09-18)**: changed-scope suite 42 tests / 244 assertions PASS; broader relevant suite 127 tests / 576 assertions PASS; scoped Pint PASS; `git diff --check` PASS. Historical 2026-07-23 preview, district dry-run, empty skip, regional morning previews, route isolation, default-off delivery, and zero Telegram actions were verified. No migration, real send, deployment, live-observer change, or push was performed.

---

## Dependencies & Execution Order

- T001 is complete from baseline research; T002–T004 establish shared route configuration.
- US1 (T005–T008) changes only the existing preview path and is the earliest user-visible checkpoint.
- US2 (T009–T011) depends on shared routes and US1 human formatting.
- US3 (T012–T016) depends only on shared routes but follows US2 to keep validation packets bounded.
- Closure (T017–T020) depends on all three stories.

## Implementation Strategy

1. Complete route foundation.
2. Deliver and verify human evening preview before any sender work.
3. Add evening delivery with all external paths inert in tests/default config.
4. Regionalize the existing morning flow without replacing provider, records, scheduler, or sender.
5. Finish only when deterministic quickstart and quality gates pass; otherwise bounded STOP with evidence.
