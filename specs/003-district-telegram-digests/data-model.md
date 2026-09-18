# Data Model: District Telegram Digests

No new persisted entities or migrations are required.

## District Route (configuration value)

- `key`: required stable lowercase district key.
- `label`: required human-readable district name.
- `chat_id`: required external Telegram forum identifier; used both to filter ledger events and as the duty-topic parent chat.
- `duty_thread_id`: required external Telegram duty-topic identifier.
- `latitude`: required numeric coordinate in `[-90, 90]`.
- `longitude`: required numeric coordinate in `[-180, 180]`.
- A route is usable only when all fields are valid; incomplete routes remain visible in diagnostics but cannot send.

## Evening Technical Projection (transient)

- Existing selected date, timezone, counts, sections, lifecycle state, confidence, uncertainty, evidence and mode fields remain.
- `district`: selected route metadata without secrets.
- `events`: complete projected technical items for debugging, including items omitted from human sections.
- Human sections contain unique qualifying event items only.

## Delivery Result (transient)

- `district`: route key and label.
- `date`: selected calendar date.
- `status`: one of `previewed`, `skipped_empty`, `blocked_disabled`, `sent`, `failed`, `invalid_route`.
- `material_events`: count of unique human-summary events.
- `telegram_actions`: `0` for preview/dry-run/blocked/empty and `1` only after one successful sender call.
- No result is persisted by this feature.

## Existing data relationships reused

```text
District Route.chat_id
  -> TelegramChat.telegram_chat_id (analytics)
  -> TelegramOperationalEvent.telegram_chat_id (analytics internal FK)
  -> TelegramOperationalEventEvidence
  -> TelegramOperationalObservation
  -> TelegramMessage

District Route
  -> morning weather coordinates
  -> matching stored MobilityAlert.district when reliable
  -> same Telegram duty destination for morning and evening
```
