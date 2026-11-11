# Unified Calendar Events Digest — Design

## Goal

Replace separate celebration messages with one readable event digest in the existing celebrations Telegram topic at 09:00 Europe/Rome, using the Academy calendar and retaining personal birthday and work-anniversary mentions.

## Source and selection

- Reuse `TeamCelebrationService` for active staff birthdays, work anniversaries, and existing personalized event text/Telegram mentions.
- Reuse `CalendarEventsService::getEventsForDay()` for active calendar event occurrences and recurrence expansion.
- Include all active `CalendarEvent` types except `vacation`: workflow, finance, holiday, peak, and strike. The operator explicitly approved publishing workflow and finance items in the shared celebrations topic, even though calendar visibility is otherwise per-user.
- Exclude generated day-off and vacation request records, ordinary weekend staffing rows, and task-calendar entries. Do not query `TaskCalendarEventService` for this digest.
- Show a multi-day calendar event on every date it covers, with its date range, matching the existing calendar view.

## Message and cards

- Render one HTML message grouped into non-empty categories: birthdays, work anniversaries, holidays, and each included calendar type. Preserve Telegram user-id links and valid username links; escape names and event titles.
- If all groups are empty, send nothing.
- For birthdays, select an optional local card by two-digit month (`01`–`12`) from `resources/images/team-celebration-cards/`; do not add assets. Missing cards use text only. Use a single `sendPhoto` only when the formatted caption fits Telegram's 1024-character caption limit; otherwise send one text message, up to 4096 characters. If the text itself exceeds its limit, fail closed without truncation or delivery.

## Safe transition and delivery journal

- Keep the existing `calendar:notify-celebrations` command signature, 09:00 schedule, Work Bot, and configured celebrations topic; change its delivery from one request per event to one digest.
- Leave the 06:00 `calendar:notify-tomorrow` schedule unchanged; it is a next-day staffing/peak notice and uses a different date and route.
- Keep historical per-event outbound rows. Before rendering, omit personal events whose legacy key is already expected, pending, sent, edited, or uncertain; include definitively failed legacy deliveries so they can be carried in the digest.
- Use a stable route-scoped daily digest operation key and `TelegramOutboundMonitor::reserveExpected()` before transport. Repeated command runs for the same date must not send a second digest. No rows or network calls are created by preview mode.

## Preview and tests

- Extend dry-run with an explicit selected date and an optional seven-day window starting on that date; a multi-day window is rejected unless dry-run is enabled.
- Cover empty dates, multiple birthdays, birthday plus holiday, work anniversary, Telegram mention escaping/link forms, daily repeat suppression, historical-operation suppression, Rome date boundaries, dry-run/no transport, event type filtering, month-card lookup, and message-length safety.
- Run the targeted calendar/celebration/monitor tests, broader Pest tests, Pint, and `git diff --check`. Do not read or modify `.env`, run migrations, or use a real Telegram transport.
