# Birthday Messages in the Forum Topic Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send each birthday greeting with that month's card to the configured forum topic on the birthday itself, while keeping the next-day calendar digest and removing empty posts.

**Architecture:** Extend the existing celebrations service and command. Send current-day birthday events individually through the current Work Bot forum route and outbound journal; keep the existing next-day digest for non-birthday events grouped and leave the shift summary untouched.

**Tech Stack:** Laravel 12, PHP 8.2+, Carbon, existing Telegram bot transport and outbound journal.

**Spec:** `docs/superpowers/specs/2026-11-18-birthday-topic-design.md`

## Global Constraints

- Use `Europe/Rome` for the celebration date.
- Use `services.telegram.chat_id_celebrations` and `services.telegram.thread_id_celebrations`.
- Require the forum topic ID; do not fall back to the forum root or work-shift chat.
- Require the month's card before reserving or sending a birthday; do not send text alone when the card is missing.
- Do not inspect or change `.env`, production destinations, or production scheduler state.
- Do not automatically retry an uncertain Telegram delivery.
- Preserve all unrelated dirty working-tree changes.
- Run focused celebration tests through `tests/run-safe-pest.ps1` after the user asks to verify the change.

## Review Focus

- The digest date remains tomorrow; birthdays are selected for the current day, including month boundaries.
- Each birthday creates one short message and selects the card for its event month.
- Birthday names do not remain in the grouped celebrations digest.
- No event produces no send; non-birthday events still produce their current grouped digest.
- Per-birthday delivery retains stable route/date idempotency and uncertain-delivery protection.

---

### Task 1: Separate birthday content from the grouped digest

**Files:**
- Modify: `app/Services/Calendar/TeamCelebrationService.php`

**Interfaces:**
- `eventsForDate(CarbonImmutable $date): Collection` continues returning a stable `operation_key` and month-based `photo_path` for each birthday.
- `formatDigest(CarbonImmutable $date, Collection $events): ?array` formats only non-birthday celebrations.

- [x] Replace the randomized birthday variants with the exact concise `🎂 С днём рождения, {name}!` text, safely escaped for Telegram HTML.
- [x] Ensure birthdays are excluded from the general grouped digest while retaining other event types and the existing `MM.jpg`, `MM.jpeg`, or `MM.png` lookup.
- [x] Review the service diff to confirm the event route key still includes the date and configured forum/topic.

### Task 2: Send birthdays on the event date and suppress empty posts

**Files:**
- Modify: `app/Console/Commands/SendTeamCelebrationsNotification.php`

**Interfaces:**
- The command continues to support `--date`, `--dry-run`, and preview-only `--days=7`.
- Birthday messages use the existing Work Bot transport, configured forum/topic, and outbound journal.

- [x] Keep the digest date and send-date guard on tomorrow in `Europe/Rome`; look up birthday events for the preceding current day and keep the existing daily schedule unchanged.
- [x] Send each birthday as one message with its month card; if that card is absent or unreadable, skip the birthday and report the missing file.
- [x] Reserve each birthday's stable operation key before sending; do not retry a photo after an uncertain result.
- [x] Keep non-birthday events in the grouped digest and remove the actual-send path that emits “На завтра событий нет” or other empty-date posts.
- [x] Keep dry-run output useful for birthday messages and confirm it never calls Telegram by control-flow inspection.
- [x] Run the command and service feature tests through the safe Pest wrapper; birthdays, empty days, separate digest, topic routing, card selection, and idempotency are covered.
- [x] Run PHP syntax checks and scoped `git diff --check` after the user requested verification.

## Review Focus Details

- Missing monthly card: do not reserve or send that birthday; report the missing asset and keep it eligible to send after the card is added.
- Several birthdays on one day: send one independently idempotent message per person, all using that month's card.
- All events already delivered: send nothing and preserve existing delivery records.
- Missing route or outbound journal: fail closed without falling back to the work-shift chat.

## Execution Notes

- Ruling: Keep the non-birthday digest date on tomorrow and select birthdays for today — this preserves the current next-day summary while meeting the requested day-of greeting — cost if wrong: other celebration dates remain previewed one day ahead.
- Ruling updated: The user explicitly requested tests, so the focused celebration suites are run with the repository's isolated Pest wrapper.
- Ruling: Suppress a birthday if the same route/date already has a handled legacy grouped digest — this avoids sending a duplicate during rollout — cost if wrong: a newly added birthday on a date whose old digest was already handled could be skipped once.
- Ruling: Do not send a text-only birthday when its monthly card is missing — the user asked for greeting plus image — cost if wrong: the greeting is delayed until that image is added.
- Ruling updated: The user's cards are named `1.jpg` through `12.jpg`, so lookup accepts both padded and unpadded month names.
- Verification: Both focused celebration files passed together (38 tests, 282 assertions). After adding unpadded month-name support for the uploaded files, the service file passed again on its own (13 tests, 73 assertions). PHP syntax and scoped whitespace checks passed. Pint still flags style fixers in the service file; the unrelated dirty profile file also has trailing whitespace and remains untouched.
