# Implementation Plan: District Telegram Digests

**Branch**: `003-district-telegram-digests` | **Date**: 2026-09-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-district-telegram-digests/spec.md`

## Summary

Keep the Spec 001 ledger and Spec 002 projection intact while separating technical projection from human presentation, adding one shared config-backed district route reader, and extending the existing preview/mobility commands into safe district slices. Evening delivery calls `TelegramBotService`, skips empty results, and is blocked by an off-by-default flag. Morning delivery reuses the same routes, parameterizes `MilanWeatherService` with route coordinates, and filters mobility only when the stored district value exactly matches a configured operational district; unknown and city-wide values remain shared.

## Technical Context

**Language/Version**: PHP ^8.2

**Primary Dependencies**: Laravel 12.56, existing Eloquent analytics models, Laravel HTTP client, existing `TelegramBotService`

**Storage**: Existing main SQLite mobility tables and separate analytics SQLite Telegram/ledger tables; no migrations or new persistence

**Testing**: Pest 4 with Laravel command tests, HTTP fakes, analytics test database, and mock sender assertions

**Target Platform**: Existing TRIS Academy Laravel server and scheduler

**Project Type**: Existing monolithic Laravel application with Artisan preview/send commands

**Performance Goals**: One bounded ledger query and one weather request per selected district; at most one Telegram send per non-empty district digest

**Constraints**: No ledger/ingestion redesign, no new sender, no real Telegram sends, no deployment, feature flag off by default, preserve legacy morning behavior when district routes are absent

**Scale/Scope**: Five configured operational districts, one duty topic per district, one daily morning and one daily evening message maximum per district

## Constitution Check

The repository constitution is an unfilled template, so repository `AGENTS.md` and `.ai` decisions are the effective gates:

- PASS: analytics models remain on the `analytics` connection.
- PASS: no schema or unrelated refactor is planned.
- PASS: existing Telegram sender is reused; no alternate HTTP path is added.
- PASS: preview/dry-run cannot write externally and evening delivery defaults off.
- PASS: exact historical and command scenarios are validated before broader tests.
- PASS: no production migration, send, scheduler execution, or deployment is performed.

Post-design re-check: all gates remain satisfied. The only new service is a thin config validator shared by two existing commands, not a new subsystem or orchestration layer.

## Execution Packets

### Packet A — Human evening presentation

Add district filtering and a complete technical event list to the existing builder, then render unique concise human sections without internal fields. Validate with unit/feature tests and the historical 2026-07-23 preview.

### Packet B — Safe district evening delivery

Add the shared route reader and manual send command. Validate mapping, dry-run, disabled flag, empty skip, and one mocked send before registering the inert-by-default schedule.

### Packet C — Regional morning flow

Parameterize the existing weather service, regionalize the existing mobility command, replace its inline HTTP sender with `TelegramBotService`, and validate two district locations plus district/city-wide mobility behavior. Preserve the legacy route path when no district config exists.

### Packet D — Deterministic closure

Run changed-scope tests, historical preview, process-local route dry-runs, scoped Pint, broader relevant suite, and `git diff --check`. One bounded repair is allowed per failed packet; otherwise stop with evidence.

## Project Structure

### Documentation (this feature)

```text
specs/003-district-telegram-digests/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── artisan-commands.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   ├── MobilityDigestCommand.php
│   ├── TelegramEveningIntelligencePreviewCommand.php
│   └── TelegramEveningIntelligenceSendCommand.php
└── Services/
    ├── Telegram/
    │   ├── TelegramBotService.php
    │   ├── TelegramDigestFormatter.php
    │   ├── TelegramDistrictRouteRegistry.php
    │   └── TelegramEveningIntelligenceBuilder.php
    └── Weather/MilanWeatherService.php
config/services.php
routes/console.php
.env.example
tests/
├── Feature/
│   ├── MobilityDigestCommandTest.php
│   ├── TelegramEveningIntelligencePreviewCommandTest.php
│   └── TelegramEveningIntelligenceSendCommandTest.php
└── Unit/
    ├── MilanWeatherServiceTest.php
    ├── TelegramDigestFormatterTest.php
    ├── TelegramDistrictRouteRegistryTest.php
    └── TelegramEveningIntelligenceBuilderTest.php
```

**Structure Decision**: Extend the existing Laravel service/command/test locations. No migration, controller, job, queue, new database, or delivery subsystem is introduced.

## Complexity Tracking

No constitution violations or disproportionate design additions are required.
