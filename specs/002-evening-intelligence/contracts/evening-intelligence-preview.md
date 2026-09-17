# Contract: Evening Intelligence Preview

## Command

```text
telegram:evening-intelligence-preview --date=YYYY-MM-DD [--json]
```

### Inputs

- `--date` is required and must be one valid calendar date in the application timezone.
- `--json` emits the machine-readable contract. Without it, the command emits the concise management preview.
- No chat, Telegram route, delivery, mutation, or scheduling option is accepted.

### Exit behavior

- Success: valid date and readable ledger, including a date with no qualifying events.
- Failure: missing/invalid date or unavailable required ledger structure.
- Validation failure occurs before event projection.

## JSON response

```json
{
  "date": "2026-07-23",
  "timezone": "Europe/Rome",
  "sections": [
    {
      "key": "attention",
      "label": "Unresolved / requires attention",
      "items": [
        {
          "event_key": "telegram:<chat>:<message>",
          "summary": "Concise existing event summary",
          "types": ["problem"],
          "status": "open",
          "confidence": "medium",
          "uncertainty": "Qualified uncertainty when present",
          "repeated": false,
          "latest_activity_at": "2026-07-23T18:10:00+02:00",
          "evidence": [
            {
              "local_message_id": 123,
              "telegram_message_id": "456",
              "role": "report",
              "transition": "created",
              "occurred_at": "2026-07-23T18:10:00+02:00"
            }
          ]
        }
      ]
    }
  ],
  "events_considered": 1,
  "events_included": 1,
  "events_omitted": 0,
  "no_material_events": false,
  "data_quality": {
    "ledger": "available"
  },
  "mode": {
    "read_only": true,
    "telegram_actions": 0,
    "mutations": 0
  }
}
```

Values inside angle brackets are identity placeholders only. The response never includes raw Telegram payloads.

## Human response

- Starts with the selected date and timezone.
- Prints only non-empty supported sections.
- Each item shows its event key, status, confidence, uncertainty when present, and compact evidence message references.
- If no item qualifies, prints one clear no-material-events statement and no empty headings.
- Ends with an explicit read-only/no-delivery statement.

## Section rules

- `attention`: unresolved operationally material events.
- `resolved`: events whose as-of-day status is resolved and whose selected-day evidence supports the outcome.
- `quality`: qualifying quality issues.
- `risks_delays`: qualifying risk or delay events.
- `positive`: only ledger-backed meaningful positive contributions.
- `tomorrow`: advisory restatement of qualifying unresolved items; it never creates a task or action.
- `key_events` is optional and may present the highest-priority distinct items without removing their traceability.

An item can appear in more than one relevant management view, but `events_included` counts distinct event keys.

## Read-only guarantees

- No insert, update, or delete against the ledger, Telegram analytics, or operational records.
- No Telegram/API/HTTP delivery.
- No queue dispatch, scheduler registration, task creation, scoring, or autonomous action.
