# Calendar Work Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing 06:00 Europe/Rome Telegram calendar message a concise, accurate plan of next-day staffing and active workload peaks.

**Architecture:** Keep `calendar:notify-tomorrow`, Work Bot routing, and `CalendarSummaryService::build(Carbon|string|null): array`. Add role summaries under `workers.by_role` while preserving legacy `shift` and `workers` keys consumed by operational context. Render only staffing categories and active `peak` events.

**Tech Stack:** Laravel 12, PHP 8.4, Carbon, Eloquent, Pest 4, existing Telegram Bot and outbound monitor.

**Spec:** `docs/superpowers/specs/2026-11-01-calendar-digest-team-celebrations-design.md`

## Global Constraints

- Keep the existing daily 06:00 Europe/Rome schedule, Work Bot, chat and thread.
- Include only active, approved `cleaner` and `supervisor` users; exclude admins and all other roles.
- Count only approved individual DayOff/Vacation days, including approved days in partially approved requests.
- Use only absence categories; never include free-text request reasons or invent sick leave.
- Preserve old `CalendarSummaryService` keys and current OI consumer behavior.
- Show only active calendar peaks in the work summary; exclude holidays, celebrations, finances, tasks, strikes and all unrelated events.
- `--dry-run` must never call Telegram.
- Do not touch production, Scheduled Controls, Mobility, WeatherAPI, strikes or authorization.

## Review Focus

- A role has no eligible users: report unavailable data, never “all available”; test both empty cleaner and empty supervisor sets.
- A partially approved request includes both approved and pending days: count only the approved day; test each request type.
- One employee has multiple absence records on the same date: list them once with a category; test precedence of approved vacation, approved DayOff, then regular weekend.
- Request reason contains private details: omit it from output; test with a distinctive private phrase.
- Calendar contains multiple event types: only active `peak` records appear; test inactive peak and active non-peak exclusion.

---

### Task 0: Isolate the approved calendar work from WeatherAPI

**Files:**
- No application files; preserve the three approved design/plan documents only.

- [ ] **Step 1: Recheck Git status** and confirm there are no user-owned modifications; do not stage or commit WeatherAPI code.
- [ ] **Step 2: Refresh `origin/main` and record its exact SHA.** The local ref currently points to `4dea860`; do not assume it is current. If remote refresh is blocked, stop before code and report the blocker.
- [ ] **Step 3: Create `codex/calendar-digest-team-celebrations` from that verified SHA** in an isolated workspace and carry only the approved design/plan docs, not the WeatherAPI commit.
- [ ] **Step 4: Verify** the new branch merge-base equals the recorded `origin/main` SHA and the branch has no unrelated application changes.
- [ ] **Step 5: Attempt the requested production read-only status check only through an already configured safe connection.** Never print secrets or personal data. If no access exists, record production as unverified and continue local-only work; no production mutation is part of the task.

---

### Task 1: Add per-role staffing summaries without changing the legacy contract

**Files:**
- Modify: `app/Services/Calendar/CalendarSummaryService.php`
- Test: `tests/Feature/TelegramTomorrowSummaryPreviewTest.php`

**Interfaces:**
- Consumes: `User::activeStaff()`, `DayOffRequestDay`, `VacationRequestDay` and their existing day-level statuses.
- Produces: `workers.by_role.cleaner` and `workers.by_role.supervisor`, each with `total`, `available_count`, `absent_count`, `available`, and `absent`. Keep legacy `workers` and `shift` entries unchanged for existing consumers.

- [ ] **Step 1: Write failing tests** for separate active/approved cleaner and supervisor totals; exclude admins, inactive and pending users; distinguish an empty role from a fully available role; cover approved, pending and rejected DayOff/Vacation days in partially approved requests; verify deduplication/category precedence and omission of private free-text reasons; assert the legacy OI contract.
- [ ] **Step 2: Run the focused test file** with the repository’s isolated test database and confirm the new assertions fail against current behavior.
- [ ] **Step 3: Add `workers.by_role`** using one eligible-staff query and approved day-level records. Do not include request reason text. Deduplicate by user; category precedence is approved vacation, approved DayOff, then regular weekend.
- [ ] **Step 4: Run `php artisan test tests/Feature/TelegramTomorrowSummaryPreviewTest.php`** and confirm the entire file passes.

### Task 2: Render the staffing-only Telegram summary and pin its timezone

**Files:**
- Modify: `app/Console/Commands/SendTomorrowCalendarEventsNotification.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/TelegramTomorrowSummaryPreviewTest.php`

**Interfaces:**
- Consumes: Task 1’s `workers.by_role`; `CalendarEventsService::getEventsForDay($date, 'peak')`.
- Produces: existing command and `--dry-run` behavior with staffing-only output; schedule explicitly pinned to Europe/Rome.

- [ ] **Step 1: Write failing format tests** for date/weekday, each role’s available/total counts, absent lists with category-only labels, all-available lines, no-data lines, and active peak entries.
- [ ] **Step 2: Assert exclusions** for holiday, birthday, work anniversary, financial, task, strike and generic calendar events; assert private request text never appears.
- [ ] **Step 3: Assert dry-run safety** by faking Telegram HTTP and verifying the command prints the preview without any request.
- [ ] **Step 4: Implement the minimal renderer change** and pass only `peak` events to it; leave website calendar events untouched.
- [ ] **Step 5: Set `->timezone('Europe/Rome')`** on the existing 06:00 schedule and test tomorrow selection across the Rome date boundary and both DST transitions.
- [ ] **Step 6: Run `php artisan test tests/Feature/TelegramTomorrowSummaryPreviewTest.php`** and confirm all assertions pass.

### Task 3: Verify work-summary integration and quality gates

**Files:**
- Test: `tests/Feature/TelegramTomorrowSummaryPreviewTest.php`
- Test: the existing focused `OperationalContextBuilder` test file identified by `rg --files tests | rg 'OperationalContextBuilder'` (create only if no focused test exists and the consumer contract needs coverage).

- [ ] **Step 1: Run the targeted calendar and OperationalContextBuilder tests**; confirm old OI fields remain unchanged.
- [ ] **Step 2: Run PHP lint and scoped Pint** on the two changed PHP files.
- [ ] **Step 3: Run `git diff --check`** and inspect the work-summary diff for unrelated behavior.
- [ ] **Step 4: Capture one synthetic full preview** using test fixtures only; label it synthetic and confirm no Telegram call occurred.
