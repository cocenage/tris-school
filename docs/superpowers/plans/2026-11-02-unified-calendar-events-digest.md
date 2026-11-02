# Unified Calendar Events Digest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Replace scattered celebration messages with one readable, deduplicated calendar digest sent at 09:00 Europe/Rome.

**Architecture:** Reuse `TeamCelebrationService` and `CalendarEventsService` for event data and mentions, then render category groups in the existing command. Preserve the route and outbound monitor, use one stable daily operation key, and keep preview side-effect-free.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Telegram Bot API, SQLite in-memory tests.

**Spec:** `docs/superpowers/specs/2026-11-02-unified-calendar-events-digest-design.md`

## Global Constraints

- Do not modify `.env`, production data, schedules, calendar sources, or the separate 06:00 tomorrow notice.
- Publish all active calendar event categories except vacations, as explicitly authorized; exclude day-off rows, ordinary weekends, and task-calendar noise.
- Use the existing Work Bot celebrations topic and `TelegramOutboundMonitor`; do not send real Telegram messages or create image assets.
- Keep historical event operation rows and avoid truncating or splitting the digest.
- Preview must not make network calls or write outbound journal rows.

## Review Focus

- Failed or ambiguous Telegram outcomes must remain suppressed by the outbound monitor's existing status semantics; verify retry behavior through the existing monitor tests.
- Multi-day and recurring events must match `CalendarEventsService` date expansion; verify date-specific selection.
- HTML titles and staff names with special characters must remain escaped while mention links remain valid; verify both supported mention link forms.
- A digest too long for Telegram must fail closed without dropping events; verify both text and photo-caption limits.
- Absence of an optional monthly card must produce a text digest and must not introduce a missing-file error.

---

### Task 1: Build grouped calendar digest data and rendering

**Files:**
- Modify `app/Services/Calendar/TeamCelebrationService.php`
- Test `tests/Feature/TeamCelebrationServiceTest.php`

**Interfaces:**
- Consumes: existing `eventsForDate(CarbonImmutable)` and `CalendarEventsService::getEventsForDay()`.
- Produces: date-specific grouped digest content with safe HTML, birthday/anniversary mentions, calendar types except vacation, and stable daily key inputs for the command.

- [x] Write focused failing tests for empty dates, several birthdays, birthday plus holiday, work anniversary, all allowed calendar types, vacation exclusion, absence/task-noise exclusion, multi-day events, and escaped Telegram mention/title text.
- [x] Run the focused test file and confirm failures describe missing grouped digest behavior.
- [x] Implement the smallest service API that preserves existing methods and event key compatibility.
- [x] Run the focused test file and confirm it passes.

### Task 2: Deliver one monitored digest and support previews

**Files:**
- Modify `app/Console/Commands/SendTeamCelebrationsNotification.php`
- Modify `tests/Feature/TeamCelebrationsNotificationTest.php`
- Update service tests as needed.

**Interfaces:**
- Consumes: Task 1 grouped digest service API.
- Produces: one date-and-route-scoped outbound operation, optional month-number card lookup, selected-date and dry-run seven-day preview, text/photo length safety.

- [x] Write failing command tests for no-event suppression, one digest request, historical per-event dedup, repeat delivery suppression, dry-run without journal or HTTP, `--days=7` dry-run output, Europe/Rome date defaults, monthly-card missing/found behavior, and 1024/4096-character boundary handling.
- [x] Run the focused command test file and confirm expected failures.
- [x] Implement single-message grouping, stable `calendar:celebrations:{date}:{routeHash}` operation key, route reservation, `MM` card lookup, and fail-closed length handling; preserve command signature and current schedule.
- [x] Run command, service, and outbound monitor tests and confirm they pass.

### Task 3: Verify and prepare the branch

**Files:**
- Review all changed files; update only directly relevant tests/docs if evidence requires it.

**Interfaces:**
- Consumes: Tasks 1–2 implementation.
- Produces: verified commit on `codex/unified-calendar-events-digest`, pushed branch, and pull request targeting `main` without merge/deployment.

- [x] Run the requested Pest suite, Pint, and `git diff --check`; inspect full diff and status.
- [x] Generate a clearly synthetic preview for 2026-11-02 using safe fixtures; do not read production data.
- [ ] Commit, push, and create the PR; confirm PR URL and pushed SHA.
