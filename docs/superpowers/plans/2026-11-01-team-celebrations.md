# Team Celebrations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send non-empty, event-specific team birthday, work-anniversary and calendar-holiday greetings through the existing Work Bot at 09:00 Europe/Rome without duplicate or unsafe retries.

**Architecture:** Reuse `CalendarEventsService` for the existing calendar and annotate event subtypes without changing stored data or website filters. Build daily celebration operations from active, approved users and active ordinary holidays; use `TelegramOutboundMonitor` with an atomic `expected`-to-`pending` claim before transport. Missing journal storage or destination fails closed.

**Tech Stack:** Laravel 12, PHP 8.4, Eloquent, Carbon, existing Telegram Work Bot, `TelegramOutboundMonitor`, Pest 4 and Laravel HTTP fakes.

**Spec:** `docs/superpowers/specs/2026-11-01-calendar-digest-team-celebrations-design.md`

## Global Constraints

- Schedule daily at 09:00 Europe/Rome; each ordinary multi-day holiday is sent once, on its start date.
- Only active and approved employees receive personal celebration notices; birthday text does not expose birth year, age or full date.
- Work anniversary is sent only for one or more full years; a February 29 occurrence maps to February 28 in a non-leap year.
- Ordinary holidays are active `CalendarEvent` records of type `holiday`; system birthday/anniversary events require explicit subtype, never title-text parsing.
- Use a separate configurable celebrations route. If the chat is missing, send nothing and never fall back to the work calendar chat.
- Use the existing Work Bot and delivery journal; failed/uncertain/reserved operations are not automatically resent.
- No third-party library, new calendar database, migration, real chat send, production write or deployment.
- The local postcard catalog is `resources/images/team-celebration-cards`; missing/unreadable cards fall back to text before any API call.

## Review Focus

- Two simultaneous scheduler invocations claim the same event: one API call at most; test atomic compare-and-set against the unique journal key.
- Telegram accepted a request but the local response is lost: status remains `uncertain`, and the next command run does not resend.
- Outbound journal table is absent: the command fails closed and sends nothing; test against a missing table.
- Birthday or anniversary is February 29 in a non-leap year: send on February 28 and compute only full work years; test both event types.
- Same-date birthday, anniversary and holiday coexist: use separate stable keys and distinct messages; test that one event neither suppresses nor overwrites another.

## Execution Precondition

- Complete Task 0 in `2026-11-01-calendar-work-summary.md` before any code changes; do not use stale `4dea860` if remote refresh is blocked.
- Before any release claim, read-only confirm the configured destination, Work Bot access and outbound journal table. Do not set the live destination, send live messages or deploy.

---

### Task 1: Classify calendar event sources without changing website behavior

**Files:**
- Modify: `app/Services/Calendar/CalendarEventsService.php`
- Test: `tests/Feature/CalendarEventsServiceTest.php` (create only if a focused service test does not already exist)

**Interfaces:**
- Consumes: existing active `CalendarEvent` rows and active-user birthday/work-start dates.
- Produces: additive `subtype` metadata: `calendar_holiday`, `birthday`, or `work_anniversary`. Keep each event’s current `type`, `id`, display text, style and recurrence behavior unchanged.

- [ ] **Step 1: Write failing tests** proving ordinary holidays and generated birthdays/anniversaries retain `type=holiday` but receive distinct subtype values.
- [ ] **Step 2: Add a regression test** for the existing event payload fields consumed by website calendar and `OperationalContextBuilder`.
- [ ] **Step 3: Add only the subtype metadata** in the relevant event expansion branches; do not infer subtype from title text or change stored rows.
- [ ] **Step 4: Run the focused CalendarEventsService tests** and verify the website event type/filter contract remains unchanged.

### Task 2: Add atomic reservation to the existing outbound journal

**Files:**
- Modify: `app/Services/Telegram/TelegramOutboundMonitor.php`
- Test: `tests/Feature/TelegramOutboundMonitorTest.php`

**Interfaces:**
- Produces: `reserveExpected(array $context): bool`. It requires a unique `operation_key`, ensures an `expected` journal row exists, atomically changes only `expected` to `pending`, and returns `false` if the row was already claimed or the journal table is unavailable.
- The existing HTTP listeners remain responsible for recording API attempts and final `sent`, `failed` or `uncertain` states.

- [ ] **Step 1: Write failing tests** for first claim succeeds, second claim fails, missing operation key/table fails closed, and `sent`, `pending`, `failed`, or `uncertain` rows cannot be reclaimed.
- [ ] **Step 2: Run the focused monitor tests** and confirm new cases fail without affecting current listener tests.
- [ ] **Step 3: Implement `reserveExpected(array $context): bool`** with a conditional database update (`status = expected`); do not alter `expect()` or global HTTP listener semantics.
- [ ] **Step 4: Test competing claims** by issuing two claims for the same key and asserting exactly one succeeds and only one sender invocation is permitted.
- [ ] **Step 5: Run `php artisan test tests/Feature/TelegramOutboundMonitorTest.php`** and verify all existing and new cases pass.

### Task 3: Build dated celebration events and safe message content

**Files:**
- Create: `app/Services/Calendar/TeamCelebrationService.php`
- Test: `tests/Feature/TeamCelebrationsNotificationTest.php`

**Interfaces:**
- Produces: `eventsForDate(CarbonImmutable $date): Collection` with normalized entries containing `kind`, stable `operation_key`, `source_type`, `source_id`, `text`, and optional local `photo_path`.
- Uses: `User::activeStaff()`, Task 1 subtype metadata, fixed Russian template arrays and the local card directory.

- [ ] **Step 1: Write failing service tests** for birthday date matching, only eligible staff, multiple static variants, ID/username/no-mention cases, and no exposure of birth year or age.
- [ ] **Step 2: Write failing anniversary tests** for first and later full years, zero years, future work-start dates, year transitions, and February 29 to February 28 mapping.
- [ ] **Step 3: Write failing calendar-holiday and image-catalog tests** for active ordinary holiday inclusion, inactive holiday exclusion, generated personal event exclusion from that branch, recurrence, multi-day start-date-only behavior, missing/unreadable cards and random selection from available files.
- [ ] **Step 4: Implement `TeamCelebrationService::eventsForDate(CarbonImmutable $date): Collection`** with one item per event and deterministic source/date/route identity; do not include raw personal dates in returned message text.
- [ ] **Step 5: Add card lookup** from `resources/images/team-celebration-cards`; select one supported readable image at random, return null when none exists, and keep text construction independent of image availability.
- [ ] **Step 6: Run the targeted celebration service tests** and verify their output contains no Telegram calls.

### Task 4: Add the celebration sender, dry-run and separate route

**Files:**
- Create: `app/Console/Commands/SendTeamCelebrationsNotification.php`
- Modify: `app/Services/Telegram/TelegramBotService.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `routes/console.php`
- Test: `tests/Feature/TeamCelebrationsNotificationTest.php`
- Create: `resources/images/team-celebration-cards/.gitkeep`

**Interfaces:**
- Command: `calendar:notify-celebrations {--date=} {--dry-run}`; defaults to today in Europe/Rome.
- Route config: `services.telegram.chat_id_celebrations` and `services.telegram.thread_id_celebrations`, sourced from `TELEGRAM_CHAT_ID_CELEBRATIONS` and `TELEGRAM_THREAD_ID_CELEBRATIONS`.
- Telegram transport: add a Work Bot `sendPhoto(string $chatId, string $path, string $caption, ?string $threadId = null): ?int` method using existing HTTP options/monitor integration; retain existing `sendMessage` behavior.

- [ ] **Step 1: Write failing command tests** for missing route (safe no-send), dry-run preview (no HTTP), text-only event, local photo event, separate messages, and explicit destination routing.
- [ ] **Step 2: Write failing idempotency tests** using the real isolated outbound journal table and fake Telegram HTTP: repeated command does not send twice; pre-existing uncertain/sent/failed/pending records do not send; reservation table missing fails closed.
- [ ] **Step 3: Add fake-HTTP tests** for `sendPhoto` Work Bot payload, multipart local image, caption, optional thread and returned message ID; ensure no token appears in logs or output.
- [ ] **Step 4: Implement config, `.env.example`, `sendPhoto`, and the command**. Reserve each event before transport; if no route or no journal, send nothing. Use text only if no image is available before sending; never send a second fallback after a failed/uncertain photo attempt.
- [ ] **Step 5: Add `calendar:notify-celebrations` at 09:00 with `timezone('Europe/Rome')->withoutOverlapping()`**; do not change existing schedule entries.
- [ ] **Step 6: Add dry-run rendering** that shows event text and whether an image would be attached, but no raw Telegram IDs and no HTTP calls.
- [ ] **Step 7: Run `php artisan test tests/Feature/TeamCelebrationsNotificationTest.php`** and confirm the full targeted file passes.

### Task 5: Integrated verification and release preparation

**Files:**
- Tests: `tests/Feature/TelegramTomorrowSummaryPreviewTest.php`, `tests/Feature/CalendarEventsServiceTest.php`, `tests/Feature/TelegramOutboundMonitorTest.php`, `tests/Feature/TeamCelebrationsNotificationTest.php`
- Modify only if required: the files listed in Tasks 1–4.

- [ ] **Step 1: Run all four focused test files** with isolated databases and fake Telegram transport; verify all requested calendar, staffing, celebration, repeat, uncertain, missing-route, image fallback, DST and no-send cases.
- [ ] **Step 2: Run PHP lint and scoped Pint** on changed PHP files; require PASS.
- [ ] **Step 3: Run `git diff --check`** and review all changed paths; exclude WeatherAPI, Mobility, strikes, Scheduled Controls and authorization.
- [ ] **Step 4: Produce fixture-only examples** of the full work summary and three celebration messages; clearly mark as synthetic.
- [ ] **Step 5: Before release preparation, refresh `origin/main` and verify the feature branch base.** Do not branch from stale `4dea860` if refresh remains blocked; stop and report the access blocker instead.
- [ ] **Step 6: Create one feature branch containing both independently tested plans; commit the coherent changes, push, and create a PR.** Do not merge, deploy, configure a real celebrations destination or send real Telegram messages.
- [ ] **Step 7: Report production as unverified until read-only configuration confirms the new destination, Work Bot access and outbound-journal table; activation remains disabled by missing route until configured.**
