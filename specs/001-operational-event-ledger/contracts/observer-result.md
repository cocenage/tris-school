# Internal Contract: Operational Observation Result

`TelegramOperationalEventObserver` is the single application entry point for live jobs and replay. It accepts a stored `TelegramMessage`, an evaluation mode (`message` or `unanswered`), and a logical evaluation time. It performs no Telegram or external network operation.

## Result Shape

```json
{
  "message_id": 123,
  "revision": "sha256:...",
  "evaluation_kind": "message",
  "outcome": "created",
  "reason_code": "operational_problem",
  "event_key": "telegram:-100123:456",
  "event_status": "open",
  "event_types": ["problem"],
  "confidence": "high",
  "uncertainty": null,
  "idempotent_reuse": false,
  "unanswered_due_at": null
}
```

## Contract Rules

- `outcome` is one of `no_event`, `created`, `updated`, `evidence`, `resolved`, `reopened`, `dismissed`, or `pending_question`.
- `event_key`, `event_status`, and `event_types` are null/empty for `no_event` and may reference the existing request event for `pending_question`.
- `reason_code` is always present for `no_event` and `pending_question`; it contains no message text or raw payload.
- `idempotent_reuse=true` means a completed observation with the same message revision and evaluation kind already existed and no write occurred.
- `unanswered_due_at` is present only when a meaningful operational question needs delayed evaluation.
- Low-confidence retained results require `uncertainty`.
- Exceptions must not return a successful result. The observer records a safe failed observation and rethrows so queue retry semantics remain effective.

## Eligibility Result Codes

Expected no-event codes include:

- `empty_content`
- `unsupported_message_type`
- `bot_or_service_message`
- `private_or_disallowed_chat`
- `ordinary_conversation`
- `insufficient_operational_signal`
- `ambiguous_correlation`

These codes support inspection without storing duplicate message content.
