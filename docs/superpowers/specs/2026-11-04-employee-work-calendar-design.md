# TRIS Academy — Employee Work Calendar / Attendance Ledger

**Status:** Approved and implemented locally; not deployed
**Date:** 2026-11-04

## Intent

Give each approved employee a private, read-only month calendar of planned work and absences, and give authorized administrators one auditable place to manage effective-dated schedules and calendar corrections. A green day means planned work, never proof of attendance.

## Current state

- `GET /profile/calendar` currently redirects to the company task/staffing calendar. The profile calendar Blade component is empty.
- The task calendar calculates staffing days in its Livewire component. `CalendarSummaryService` independently calculates the same basic schedule and is also used by tomorrow-calendar delivery and `OperationalContextBuilder`.
- `users.work_started_at` and `users.weekend_days` hold only current values. DayOff and Vacation already have per-date rows with pending/approved/rejected status. There is no effective-dated schedule history or manual calendar-adjustment ledger.
- Existing `user_calendar_type_accesses` controls visibility of event categories; `user_panel_accesses` grants whole-panel access. Neither can express view-all, edit, or export rights for employee calendars.
- The existing stack already has Laravel 12, Livewire 4, Filament 5, Alpine, Tailwind, and a custom responsive calendar. No calendar package is needed.

## Goals

- Add “Мой календарь” to the employee profile, limited on the server to the authenticated approved employee's own calendar.
- Use one range-based day-state service for the personal page, Filament, company/employee statistics, existing staffing summary, and reports.
- Reuse DayOff/Vacation date rows as the only source for submitted absences; do not copy them into a second request table.
- Support approved absences, pending indicators that preserve the base day state, rejected requests that do not change it, overlapping reasons, and audited manual corrections.
- Store schedule changes with an effective date and preserve the schedule that applied to earlier dates after a later change.
- Place the management interface in the current Admin Filament panel and UserResource. Admins have full access; supervisors receive only explicitly granted calendar capabilities.
- Require a non-empty reason for every manual schedule or calendar change. Keep the audit trail visible and do not provide delete/overwrite actions.
- Provide month/year filters, employee filters, per-employee and company counts, and permission-checked export, all from the same day-state projection.

## Non-goals and anti-goals

- Do not record actual presence, clock-in, or proof of attendance.
- Do not alter Telegram notifications, request submission/approval rules, or physically delete approved requests.
- Do not expose another employee’s calendar through client Livewire state or URL parameters.
- Do not add a Filament panel, a calendar dependency, or an independent absence/request system.
- Do not backfill guessed historical attendance or treat the current weekly schedule as evidence of historical attendance.

## Approaches considered

1. **Separate schedule versions and adjustment ledger (chosen).** Keep request-day tables canonical; add an effective-dated schedule-version table and an append-only manual-adjustment table, plus a small permission table. This makes recurring schedules, one-off corrections, and authorization explicit while sharing one projection service.
2. **One typed event ledger for schedules and exceptions.** Fewer tables, but schedule transitions and date-specific absence corrections have different validation and query rules; projection and reversal logic become harder to audit.
3. **Reuse only `weekend_days` and request tables.** Smallest initial diff, but schedule edits retroactively change history and manual corrections cannot have a reliable audit log. It cannot meet the requirements.

The owner chose approach 1. The existing day-off and vacation records remain the source of those absences.

## Data and projection design

- Add `employee_work_schedule_versions`: employee, effective date, captured employment-start date and regular days off, previous/new values, actor, required reason, timestamps. New versions are inserted for changes; earlier versions are not overwritten. Ordinary recurring-schedule changes may take effect today or in the future only. A past date can be changed only through an explicit, reasoned calendar correction.
- Add `employee_calendar_adjustments`: employee, affected date or range, correction type/category, previous/new effective state, optional source request type/id and source request date, optional replacement date, actor, required reason, optional reversal link, timestamps. Corrections are append-only from the UI; cancellation/rescheduling is recorded as a new audited correction, not a delete. When an adjustment cancels or moves an approved request date, it explicitly supersedes that request-day in the current projection while preserving the original request and approval history.
- Add `user_calendar_permissions` with separate `can_view_all_calendars`, `can_edit`, and `can_export`. Admin role bypasses these checks. Non-admin access is denied by default; editing/export also require view-all permission.
- Initial schedule snapshots start at feature activation. Regular schedules before that boundary are shown as **“График не зафиксирован”** unless a request or manual record supplies a known event. Do not project today's `weekend_days` backward. For later dates, select the latest schedule version effective on that date. The existing profile editor may still change `work_started_at`, but a change requires a non-empty reason and appends a schedule-version record effective today; the new value cannot silently rewrite calendar dates before that change. Initial profile completion is also audited when it first sets this value.
- Implement one range-based `EmployeeWorkCalendarService` returning one record per employee/date with `planned_work`, `regular_day_off`, `approved_absence`, `unknown_schedule`, or `not_employed`, plus pending indicators and all applicable source details. Dates before the captured employment start are neutral and excluded from work and absence counts. Source precedence: an explicit calendar correction sets the effective calendar state; otherwise approved absence(s) determine the yellow state; otherwise apply the effective schedule. Pending and rejected requests remain separate history/indicators and never overwrite the base state. Multiple sources on a date produce one counted day with a list of reasons.
- Update `CalendarSummaryService` and the existing staffing page to consume this service while preserving the response shape and routes used by tomorrow notifications and `OperationalContextBuilder`.
- The employee page reads only `auth()->id()` and exposes no mutating Livewire action. The admin UI and reports call the same service with a permitted employee/date range.

## Access and interface

- Keep the current `auth` and `approved` route middleware. The server resolves the client calendar owner from the authenticated user only.
- Replace the legacy `/profile/calendar` redirect with the personal page, and link it from the existing profile.
- In UserResource, add a “Рабочий календарь” section with schedule history, corrections, and request history. Add an admin report view inside the existing Admin panel for monthly company/employee statistics and CSV export; do not create another panel.
- Admin always receives view, edit, and export. Supervisors get only the individual flags explicitly granted by an administrator. Other employees cannot view all calendars or edit/export.
- Corrections and schedule changes run in transactions, validate date/range/reason server-side, record actor and source, and cannot be deleted through the UI.

## Day-state presentation and counting

- Green: scheduled work. Red: regular day off. Yellow: approved vacation/day-off/manual absence. Pending uses a separate indicator and leaves the base color intact.
- Dates before schedule-history activation use a neutral unknown state unless a request/correction provides a known status.
- Rejected requests are visible in the authorized history but do not change day color.
- A date with overlapping request and correction sources is counted once; details preserve each source and category.
- Statistics count unique employee-days from the projection. Pending applications and manual changes are separate counts and are not added to worked/absence days.

## Migration and rollout

- Use additive primary-database migrations only; no Analytics tables, Telegram writes, or request backfill into a duplicate absence table.
- Migration creates tables and records activation-boundary schedule snapshots from current user data without changing User or request rows. Existing historical regular-schedule transitions cannot be reconstructed; the UI marks pre-activation dates unknown.
- Production migration/deployment is a separate approval. No production database or Telegram operation is part of this implementation.

## Verification

Targeted Pest coverage must verify scheduled work, regular days off, pre-activation unknown dates, pending/approved/rejected/partially approved requests, overlaps without double counting, manual add/cancel/move, non-empty reasons, append-only history, effective schedule changes, migration baseline behavior, and client/admin/supervisor authorization including attempted Livewire parameter tampering. Verify monthly totals and export permission from the shared projection, and preserve tomorrow-summary output. Run scoped Pint, the relevant Vite build for the Blade page, and `git diff --check`. Browser acceptance is required if the local browser is available; otherwise report that gap.

## Builder decisions

- The calendar is a planned schedule and absence ledger, not actual attendance.
- Old regular schedules before activation are unknown, rather than inferred from today's `weekend_days`.
- Corrections affect the effective calendar projection and retain the original request as history; they do not delete the request row.
- Same-day overlapping sources are displayed together and counted once.

## Builder review decisions

- Ordinary recurring-schedule changes become effective no earlier than today. Past calendar dates may be corrected only through the separate append-only correction log with a reason.
- Changes to `work_started_at` in the existing employee profile remain available but require a reason and create an effective-dated schedule version; the changed value is effective from that edit date for this calendar, preserving earlier projections.
- Cancelling or moving an approved request creates a correction that supersedes the specific request-day in the effective projection without deleting or mutating the original request/approval history.
