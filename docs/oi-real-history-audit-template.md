# OI real-history audit

Use this template for one date and one configured district at a time. Keep the raw command output in an access-controlled server session. Do not commit unredacted Telegram text, employee names, phone numbers, access codes, or unrelated personal data. The completed report should contain only the identifiers and excerpts necessary to explain a finding.

## Existing audit command

`telegram:oi-logic-audit` requires `--date=YYYY-MM-DD` and supports `--district=<configured-key>`, `--event=<ledger-id-or-event-key>`, `--apartment=<id>`, and `--json`.

It is read-only. It runs the current evening builder over the stored ledger, reads Telegram messages and bounded same-chat/topic context, and reads linked application user names. JSON includes event IDs/keys, district/apartment/topic context and mapping, current type/status/summary/confidence, attention and next action, selected editorial quote, resolution/time, evidence roles/transitions/status/timestamps, source text, reply IDs, author labels, media metadata, rendered sections, and aggregate counts. Related conversation is limited to 15 minutes around evidence timestamps, same chat/topic, up to 80 messages.

It does **not** expose the current interpreter's per-message decision, observation outcome/reason code, subject key, correlation target/reason, or `carry_over`. It does not list source messages with no event. Its editorial fields are current builder projections over historical events, not a current-code observer replay. The output contains raw source text and author labels without a general secret/PII redactor; keep it private and redact before sharing.

## Select one candidate date

Run on the intended server only after confirming the active Laravel environment and analytics connection. This aggregate query returns dates and counts only:

```sh
php artisan tinker --execute='\App\Models\TelegramMessage::query()->selectRaw("DATE(sent_at) AS audit_day, COUNT(*) AS messages")->whereNotNull("sent_at")->groupBy("audit_day")->orderByDesc("messages")->limit(20)->get()->each(fn ($row) => dump(["date" => $row->audit_day, "messages" => $row->messages]));'
```

Choose one busy date and a configured district with roughly 30–50 meaningful episodes after review. The count is source messages, not operational episodes.

## Current interpreter comparison

For the selected date and district, this bounded read calls only `TelegramOperationalInterpreter::interpret()`; it does not call the observer or write observations/events/evidence. It emits source text, so run it only in a restricted server session and redact before retaining or sharing. The 500-message limit is a safety cap; if reached, narrow the slice or process a deliberate smaller interval.

```sh
php artisan tinker --execute='$day = "YYYY-MM-DD"; $district = "district-key"; $from = \Carbon\Carbon::parse($day, config("app.timezone", "Europe/Rome"))->startOfDay(); $to = $from->copy()->endOfDay(); $route = app(\App\Services\Telegram\TelegramDistrictRouteRegistry::class)->find($district); if (! $route) { throw new \RuntimeException("Unknown district route"); } $interpreter = app(\App\Services\Telegram\TelegramOperationalInterpreter::class); \App\Models\TelegramMessage::query()->where("telegram_chat_id", $route["chat_id"])->whereBetween("sent_at", [$from, $to])->orderBy("sent_at")->limit(500)->get(["id", "telegram_topic_id", "sent_at", "text", "caption", "raw"])->map(fn ($message) => ["source_message_id" => $message->id, "timestamp" => $message->sent_at?->toIso8601String(), "district" => $route["label"], "topic_id" => $message->telegram_topic_id, "has_reply" => data_get($message->raw, "message.reply_to_message.message_id") !== null, "text" => $message->text ?: $message->caption, "current_interpretation" => $interpreter->interpret($message->text ?: $message->caption)])->each(fn ($row) => dump($row));'
```

This is message-level interpretation only. It does not simulate event correlation, continuation, merge/split, or lifecycle transitions. The observer performs writes inside an analytics transaction, so do not call it on stored messages for this audit. Do not use transaction rollback as a replay substitute.

## Stored ledger and evening projection

After selecting the date and district, inspect the stored ledger and current evening projection:

```sh
php artisan telegram:oi-logic-audit --date=YYYY-MM-DD --district=district-key --json
```

This reads the historical ledger as stored. Older rows may predate current hardening. Do not label them as current observer output. Compare source text/current interpreter output separately with historical event/evidence and current builder editorial output.

## Ground-truth episode record

Use one row per episode; put multiple related source message IDs in `source_message_ids`. Keep notes concise and redacted.

```csv
episode_ref,source_message_ids,expected_event,expected_primary_type,expected_subject,expected_context,expected_lifecycle,expected_attention,expected_resolution,notes
```

Allowed manual labels:

- Detection: correct event, missed event, false positive, correct no-event.
- Entity/subject: correct subject, wrong subject, unsafe merge, unnecessary split.
- Context: correct apartment/topic, missing context, wrong context.
- Lifecycle: correct open, correct continuation, correct resolution, missed resolution, premature resolution, stale active event.
- Impact: correct attention, unnecessary attention, missing attention, redundant next action, missing next action.
- Output: good summary, misleading summary, context-dependent/raw output, omitted useful event, included low-value noise.

Report counts by label/category. Do not calculate an aggregate AI quality score.

## Current limitations

No real date was selected from this workspace because no production analytics query was run. The existing command cannot identify no-event source messages or report current interpreter/correlation decisions. The pure interpreter read above covers only message-level classification; validating current observer correlation/lifecycle against real history needs a dedicated safe dry-run design and is outside this manual audit pass. Do not modify production data or replay history to fill this gap.
