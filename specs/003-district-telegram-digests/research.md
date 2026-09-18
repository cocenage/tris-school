# Research: District Telegram Digests

## Existing evening split

**Decision**: Keep `TelegramEveningIntelligenceBuilder` as the deterministic ledger projection and `TelegramDigestFormatter` as presentation-only logic.

**Rationale**: The builder already reconstructs selected-day lifecycle and evidence. The current human noise is caused by formatter output and duplicated section membership, not missing intelligence infrastructure.

**Alternatives considered**: A generative rewriting layer was rejected because it would weaken determinism and violate scope; rebuilding from raw messages was rejected because Spec 001 is authoritative.

## District ownership

**Decision**: Use the event's existing analytics chat relationship as the district source boundary and require an explicit configured route for each district.

**Rationale**: The five work forums already define operational boundaries. Imported topic titles are mostly technical placeholders, so duty topics cannot be safely inferred from titles.

**Alternatives considered**: Guessing districts from event text or apartment topics was rejected as unreliable; hardcoding Telegram identifiers was rejected as unsafe and non-configurable.

## Shared route representation

**Decision**: Add one small config-backed registry returning only complete, validated district routes with key, label, forum identifier, duty topic identifier, latitude, and longitude.

**Rationale**: Morning and evening need the identical destination and weather location. One validator prevents divergent parsing while remaining a thin adapter over existing config.

**Alternatives considered**: Duplicating route parsing was rejected because it risks mismatched destinations; a database mapping table was rejected because persistence is unnecessary.

## Evening delivery safety

**Decision**: Use one manual Artisan command with dry-run, an off-by-default feature flag, empty-summary suppression, and `TelegramBotService` for the only real-send path. Register scheduled invocation only after tests, relying on the same flag gate.

**Rationale**: This reuses the established sender and scheduler while making default and dry-run behavior externally inert.

**Alternatives considered**: A new job/queue and direct HTTP calls were rejected as unnecessary parallel architecture.

## Weather failure baseline

**Decision**: Parameterize the existing service and add deterministic failure diagnostics/tests rather than replacing Open-Meteo.

**Rationale**: On 2026-09-18 the existing provider returned valid weather in 2.9 seconds. The local `mobility:digest` failure occurred earlier because the selected main SQLite copy lacked `mobility_alerts`; it was not a weather failure. Production-specific “unavailable” cannot be attributed without production logs, while the existing fallback works but non-success responses are under-diagnosed.

**Alternatives considered**: Switching providers was rejected without evidence. Running unrelated migrations against the local production copy was rejected by safety rules.

## Mobility geography

**Decision**: Treat a mobility item as district-specific only when its stored `district` value exactly matches a configured operational district key/label. Null, city-wide, transit-line values such as M1–M5, and unknown values remain shared.

**Rationale**: Existing `district` data often represents transit lines rather than operational areas. Text inference would invent geography.

**Alternatives considered**: Keyword/geocoding inference was rejected as unreliable and outside scope.

## Morning compatibility

**Decision**: Use district routes when configured; otherwise retain the existing legacy target list and city-center weather behavior, but route actual sends through `TelegramBotService`.

**Rationale**: This avoids silently stopping an existing scheduled digest before deployment configuration is ready.

**Alternatives considered**: Removing the legacy target path was rejected as a production regression risk.
