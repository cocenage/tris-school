# Employee Work Calendar Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Deliver an auditable, effective-dated employee work calendar with a private employee view, permission-scoped Filament management, and shared statistics.

**Architecture:** Add effective-dated schedule versions, append-only calendar corrections, and explicit calendar permissions. A single range-based EmployeeWorkCalendarService projects day states from those records and canonical DayOff/Vacation day rows; existing staffing summaries consume that projection without changing their public return shape.

**Tech Stack:** Laravel 12, Livewire 4, Filament 5, Blade, Tailwind 4, Pest, primary SQLite connection.

**Spec:** docs/superpowers/specs/2026-11-04-employee-work-calendar-design.md

**Implementation status (2026-11-05):** Feature implementation and focused checks are complete locally. The pre-implementation RED snapshots were not captured; Vite/browser verification remains unavailable. Do not interpret those omissions as evidence that the feature is incomplete or visually accepted.

## Global Constraints

- This is a planned-work and absence ledger, not actual attendance.
- DayOff/Vacation request-day rows remain canonical; do not duplicate, mutate, or delete their history.
- Regular schedules before feature activation are unknown, not inferred backward from today's values.
- Recurring schedule changes become effective today or later; past changes require an explicit audited correction.
- A changed work_started_at in the profile requires a reason and an effective-dated audit entry.
- Supervisors have separate view-all, edit, and export permissions, default-deny; admins bypass them.
- Do not change Telegram, polling, request approvals, production data, or unrelated profile behavior.
- Migrations are additive and are never run against production in this task.
- All tests use the project's isolated Pest database.

## Review Focus

- Tampered employee IDs on the self-service Livewire page must not disclose another user's data; test route and hydration/action tampering in EmployeeWorkCalendarAuthorizationTest.
- A schedule revision must not rewrite dates before its effective date; test the day before and effective day in EmployeeWorkCalendarServiceTest.
- Cancelling or moving an approved request must preserve its record but supersede only its linked request-day; test in EmployeeWorkCalendarAdjustmentsTest.
- Concurrent schedule edits must preserve audit history or reject stale state without losing an update; test in EmployeeWorkCalendarManagementTest.
- SQLite migration must create the additive schema and activation snapshots without changing users or request rows; test in EmployeeWorkCalendarMigrationTest.

---

### Task 1: Add versioned schedule, correction, and permission storage

**Files:**
- Create: database/migrations/<timestamp>_create_employee_work_schedule_versions_table.php
- Create: database/migrations/<timestamp>_create_employee_calendar_adjustments_table.php
- Create: database/migrations/<timestamp>_create_user_calendar_permissions_table.php
- Create: app/Models/EmployeeWorkScheduleVersion.php
- Create: app/Models/EmployeeCalendarAdjustment.php
- Create: app/Models/UserCalendarPermission.php
- Modify: app/Models/User.php
- Test: tests/Feature/EmployeeWorkCalendarMigrationTest.php

**Interfaces:**
- User exposes workScheduleVersions(), calendarAdjustments(), and calendarPermission().
- User exposes canViewAllEmployeeCalendars(), canEditEmployeeCalendars(), and canExportEmployeeCalendars(); admin returns true, other roles default to explicit grants, and edit/export also require view-all.
- Schedule version stores employee, effective_from, captured employment_start_date, regular weekend_days, previous/new values, actor, reason, timestamps.
- Adjustment stores employee, date/range, operation/category, previous/new state, optional superseded request type/id/date, optional replacement date, actor, reason, reversal link.
- Permission stores independent can_view_all_calendars, can_edit, and can_export flags.

- [ ] Step 1: Write migration/model tests for schema, indexes, foreign keys, false permission defaults, activation-boundary snapshots, and unchanged users/request rows.
- [ ] Step 2: Run php artisan test --compact tests/Feature/EmployeeWorkCalendarMigrationTest.php and confirm it fails before implementation.
- [ ] Step 3: Add the three additive migrations, Eloquent models, and User relations/helpers. Snapshot only the current recurring schedule at activation; missing values stay unknown. Do not update user or request records.
- [ ] Step 4: Rerun the migration test and verify all assertions pass on isolated SQLite.
- [ ] Step 5: Run vendor/bin/pint --dirty.

### Task 2: Implement the shared date projection

**Files:**
- Create: app/Services/Calendar/EmployeeWorkCalendarService.php
- Modify: app/Services/Calendar/CalendarSummaryService.php
- Modify: resources/views/components/tasks/⚡page-calendar.blade.php
- Test: tests/Feature/EmployeeWorkCalendarServiceTest.php
- Regression test: tests/Feature/TelegramTomorrowSummaryPreviewTest.php

**Interfaces:**
- EmployeeWorkCalendarService::forEmployee(User $employee, CarbonInterface $from, CarbonInterface $through): Collection returns one row per date with date, state, pending, sources, and schedule_known.
- EmployeeWorkCalendarService::forEmployees(iterable $employees, CarbonInterface $from, CarbonInterface $through): Collection returns employee-id keyed projections for unique employee-day counts.
- State values: planned_work, regular_day_off, approved_absence, unknown_schedule, not_employed. Dates before the captured employment start are neutral and excluded from work/absence counts. Pending is a separate indicator; rejected rows are history only.
- Explicit adjustment sets effective state; otherwise approved, non-superseded request-day rows set absence; otherwise the effective schedule version determines work/off/unknown.
- CalendarSummaryService::build() retains its existing keys and nested shape.

- [ ] Step 1: Add tests for effective dates, activation-boundary unknown dates, pending/approved/rejected/partially approved requests, overlaps counted once, and exact supersession of an approved request-day.
- [ ] Step 2: Run tests and confirm failure before the projection service exists.
- [ ] Step 3: Implement bounded range projection with bulk eager-loaded schedule versions, request-day rows, and adjustments; emit exactly one record per employee-date with all source details.
- [ ] Step 4: Add parity tests for CalendarSummaryService and the existing tomorrow digest output.
- [ ] Step 5: Adapt CalendarSummaryService and task-calendar calculations to use the projection while preserving public return shape, existing routes, and displayed staffing semantics.
- [ ] Step 6: Run php artisan test --compact tests/Feature/EmployeeWorkCalendarServiceTest.php tests/Feature/TelegramTomorrowSummaryPreviewTest.php and verify PASS.

### Task 3: Add the private employee calendar

**Files:**
- Modify: routes/web.php
- Modify: resources/views/components/profile/⚡page-calendar.blade.php
- Modify: resources/views/components/⚡page-profile.blade.php
- Test: tests/Feature/EmployeeWorkCalendarAuthorizationTest.php
- Test: tests/Feature/EmployeeWorkCalendarPageTest.php

**Interfaces:**
- Replace GET /profile/calendar redirect with Livewire route page-profile.calendar under existing auth and approved middleware.
- Component resolves employee only from auth()->user(); no public employee-id property or mutating action is exposed.
- Month selection is validated server-side; all data comes from EmployeeWorkCalendarService::forEmployee().

- [ ] Step 1: Add tests for own-calendar access, guest/unapproved denial, tampered ID attempts, read-only behavior, all day colors/states, pending overlay, source details, and unknown pre-activation schedule.
- [ ] Step 2: Run tests and confirm failure before implementation.
- [ ] Step 3: Implement a responsive month grid with green planned-work, red regular-off, yellow approved-absence, neutral unknown, separate pending indicator, and accessible day details.
- [ ] Step 4: Add a “Мой календарь” link to the existing profile only.
- [ ] Step 5: Run php artisan test --compact tests/Feature/EmployeeWorkCalendarAuthorizationTest.php tests/Feature/EmployeeWorkCalendarPageTest.php and verify PASS.

### Task 4: Add audited management operations and supervisor permissions

**Files:**
- Modify: app/Filament/Resources/Users/UserResource.php
- Modify: app/Filament/Resources/Users/Schemas/UserForm.php
- Modify: app/Filament/Resources/Users/Pages/EditUser.php
- Create: app/Filament/Resources/Users/RelationManagers/WorkScheduleVersionsRelationManager.php
- Create: app/Filament/Resources/Users/RelationManagers/CalendarAdjustmentsRelationManager.php
- Create: app/Services/Calendar/EmployeeWorkCalendarManagementService.php
- Modify: resources/views/components/⚡page-profile.blade.php
- Test: tests/Feature/EmployeeWorkCalendarManagementTest.php
- Test: tests/Feature/EmployeeWorkCalendarAuthorizationTest.php

**Interfaces:**
- EmployeeWorkCalendarManagementService::changeSchedule(User $employee, array $newSchedule, CarbonInterface $effectiveFrom, User $actor, string $reason): EmployeeWorkScheduleVersion validates effectiveFrom >= today and appends a version transactionally.
- ::adjust(User $employee, array $change, User $actor, string $reason): EmployeeCalendarAdjustment validates actor, employee, source request ownership/date, correction type, and non-empty reason, then appends a correction.
- ::recordProfileStartDateChange(User $employee, ?CarbonInterface $oldDate, CarbonInterface $newDate, User $actor, string $reason): EmployeeWorkScheduleVersion records the profile change effective today.
- Management locks the current version row within a short transaction. No Telegram/network work runs inside it. Audit rows have no delete/overwrite UI.

- [ ] Step 1: Add failing tests for schedule changes, past-date rejection, corrections, cancel/move supersession, invalid request source, concurrency/stale version, required reason, and profile date audit.
- [ ] Step 2: Run tests and confirm expected failures.
- [ ] Step 3: Implement transactional append-only operations and validation.
- [ ] Step 4: Add UserResource relation managers and three separate permission toggles; supervisors default deny, edit/export require view-all, admins always allowed.
- [ ] Step 5: Require a reason when the profile start date changes and call the management service in the existing profile save transaction.
- [ ] Step 6: Test Filament authorization and direct-action denial for admin, supervisor permission combinations, and employees.
- [ ] Step 7: Run php artisan test --compact tests/Feature/EmployeeWorkCalendarManagementTest.php tests/Feature/EmployeeWorkCalendarAuthorizationTest.php.

### Task 5: Add monthly/yearly statistics and CSV export

**Files:**
- Create: app/Services/Calendar/EmployeeWorkCalendarStatisticsService.php
- Create: app/Filament/Pages/EmployeeWorkCalendarReport.php
- Create: resources/views/filament/pages/employee-work-calendar-report.blade.php
- Test: tests/Feature/EmployeeWorkCalendarStatisticsTest.php
- Test: tests/Feature/EmployeeWorkCalendarAuthorizationTest.php

**Interfaces:**
- EmployeeWorkCalendarStatisticsService::forPeriod(CarbonInterface $from, CarbonInterface $through, ?int $employeeId = null): array derives counts only from the shared projection.
- Report counts planned workdays, regular off days, approved day-off, approved vacation, approved unpaid leave, pending request-days, and manual changes. Count each employee-date once.
- Report supports month/year and optional employee filter. CSV export requires can_export and uses the same authorized filters.

- [ ] Step 1: Add tests for month/year aggregation, overlaps counted once, pending separation, employee filter, and export permission.
- [ ] Step 2: Run tests and confirm failure before implementation.
- [ ] Step 3: Implement aggregate service from projection rows, without duplicating state rules.
- [ ] Step 4: Add a page in the existing Filament panel and streamed CSV export behind the separate permission check.
- [ ] Step 5: Run php artisan test --compact tests/Feature/EmployeeWorkCalendarStatisticsTest.php tests/Feature/EmployeeWorkCalendarAuthorizationTest.php.

### Task 6: Final targeted verification and branch handoff

**Files:**
- Review only the files listed in Tasks 1–5.

- [ ] Step 1: Run the combined targeted Pest files from Tasks 1–5 through the isolated test database.
- [ ] Step 2: Run vendor/bin/pint --dirty and git diff --check.
- [ ] Step 3: Run npm run build only if Blade/CSS source changes require compilation; do not change unrelated generated assets.
- [ ] Step 4: Check desktop/mobile in an available local browser; if unavailable, report BROWSER NOT VERIFIED.
- [ ] Step 5: Inspect final diff and have one fresh reviewer inspect the completed branch.
- [ ] Step 6: Commit only this calendar feature, push codex/employee-work-calendar, and prepare a PR to main. Do not merge or deploy.
