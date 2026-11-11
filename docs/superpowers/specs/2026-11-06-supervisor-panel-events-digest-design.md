# Supervisor Panel and Daily Events Digest — Design

**Status:** Approved
**Date:** 2026-11-06
**Base:** `origin/main` at `d95aa73a1ae1734650551020eeb8a2bd9abdf214`

## 1. Goal and boundaries

Deliver one isolated feature branch containing the requested supervisor-facing panel/profile update and next-day celebration digest change. Reuse existing Academy models, services, authorization records, calendar data and Telegram outbound journal. Do not create a new event system, duplicate business entities, alter control scoring or forms, touch production, or deploy.

The worktree remains isolated from other checkouts. No schema changes are planned. The three existing employee-work-calendar migrations are deployment prerequisites in their timestamp order.

## 2. Confirmed current behavior

### Panel and profile

- `EducationPanelProvider` is panel ID `education`, path `admin/education`, brand `Админ-панель обучения`. It explicitly registers `UserResource`, `DayOffRequestResource`, and `VacationRequestResource`; the Education discovery directory is absent on current main.
- `User::canAccessPanel()` rejects non-approved users, admits admins, and otherwise checks `UserPanelAccess` for the panel ID. Keep panel ID `education` and all existing grants unchanged.
- The registered `UserResource` exposes identity, role/access editing, Telegram/auth fields and general edit forms. It cannot be exposed to supervisors unchanged. `TelegramUserNotificationService::userAdminUrl()` generates nested old-path edit links.
- Request resources include approve/reject/comment/edit actions whose visibility is currently UI-level; their closures update records directly. Server authorization must protect every supervisor-accessible mutation.
- Quality resources expose editable Control configuration and ControlResponse pages. A saved response already contains comments, negative answers, corrective-action fields, repeat/recheck flags and photo/video metadata. Keep results read-only in this panel.
- `ApartmentAccessService` filters visible apartments by active/published state and explicit grants; apartment access-grant management is restricted to admins or users with admin-panel access.
- `Task::visibleFor(User)` and authenticated `/tasks` routes provide the task surface. The general event calendar reads `allowedCalendarTypes()`; employee work-calendar permissions are separate.
- `/profile/calendar` already provides a personal work calendar for cleaners and supervisors. `/tasks/calendar` is the general calendar. The profile currently includes Finance and Education links, and the Education link does not call the complete panel access check.
- `User::allowedCalendarTypes()` can include `finance` for a non-admin with that explicit category grant. Because supervisor access must exclude financial data, calendar category filtering and the profile entry point must exclude `finance` for supervisors while leaving the underlying grant untouched. Admin behavior remains unchanged.

### Celebrations and calendar

- `calendar:notify-tomorrow` is already scheduled for 06:00 Europe/Rome and must remain unchanged.
- `calendar:notify-celebrations` currently defaults to today and is scheduled for 09:00 Europe/Rome.
- `TeamCelebrationService::eventsForDate()` gathers active-staff birthdays and work anniversaries plus configured calendar events. `formatDigest()` returns null for an empty list.
- The command filters already-delivered legacy personal events by their per-event `operation_key`. It distinguishes a truly empty source day from a day whose personal events were all deduplicated.
- Delivery uses the dedicated celebrations chat/thread, `TelegramOutboundMonitor::reserveExpected()`, `withContext()`, and requires a numeric Telegram message receipt. Operation identity contains the date and destination fingerprint. Only an `expected` operation can be reserved; sent, pending, failed and uncertain records fail closed.
- Existing tests cover personal-event legacy dedupe, uncertain delivery, message limits, Rome timezone, dry-run, and the current 09:00 schedule. A truly empty date currently sends nothing.
- The request mentions a manual production correction for empty days. Production state has not been inspected. The implementation can honor any existing operation row only if it uses the existing date+destination digest key; no claim will be made about unknown historical production keys.

## 3. Proposed panel design

### Identity and compatibility

- Change visible name to **Панель супервайзера** and URL path to `/admin/supervisor`.
- Keep internal Filament panel ID `education`; do not rewrite or migrate `UserPanelAccess` records or grant access to new users.
- Keep existing `User::canAccessPanel()` panel-entry semantics: approved admin or an existing explicit `education` grant. New supervisor operations additionally require an approved supervisor (admins retain existing admin authority); a panel grant alone never grants an unrelated operation.
- Keep `UserResource` registered for admin-only access so existing notification edit links continue to resolve after the redirect. Enforce admin-only authorization at the resource and page/action levels; supervisors use a curated employee surface instead.
- Add an authenticated legacy redirect route for GET/HEAD requests from `/admin/education/{path?}` to the same relative path beneath `/admin/supervisor`; give the optional `path` parameter a wildcard constraint such as `where('path', '.*')` so nested URLs (including `/users/{id}/edit`) are matched. Check `canAccessPanel()` before redirecting; guests go through the new panel login and authenticated users without access receive 403. Do not accept an absolute redirect target. Keep the route shape for admin-only edit links, and update `TelegramUserNotificationService` to generate the new path for future links.
- Use explicit resource/page registration only. Do not broadly discover global Filament resources in the supervisor panel.

### Navigation groups and permitted data

| Section | Surface | Allowed behavior |
| --- | --- | --- |
| Сотрудники и графики | Curated employee view over the existing User model; existing work-calendar report and relation managers | Show only approved, active cleaners and supervisors. Read only safe profile fields. Show schedule history/availability to users with `canViewAllEmployeeCalendars()`; allow schedule changes/corrections only through `EmployeeWorkCalendarManagementService` and its `canEditEmployeeCalendars()` authorization. Never expose roles, panel grants, finance access, tokens, login/auth data, or generic User edit/create/delete to supervisors. |
| Заявки | Existing day-off and vacation resources | List/view status and approval history. Supervisor approve/reject/comment operations require server authorization and approved supervisor panel access; validate request/day state. Disable generic create/edit/delete forms for supervisors unless specifically required to process an existing request. Preserve admin behavior. |
| Контроль качества | Existing Control and ControlResponse data | List/view form definitions and submitted results, including stored comments, corrective action, repeat/recheck values, and permitted media. No supervisor create/edit/delete for Control configuration; no arbitrary edits to submitted results. Keep result data read-only. |
| Рабочие инструменты | Existing apartments, tasks, instructions, and calendar events | Apartments continue through `ApartmentAccessService::visibleQuery()`; no supervisor access-grant management. Tasks reuse authenticated routes and `Task::visibleFor()`. Instructions are read-only. Calendar-event queries/actions use the actor's allowed categories, with `finance` excluded for supervisors even if an old explicit grant exists; do not modify grants. |

Global admin, finance, role/user access management, Telegram topics/messages/tokens, and system configuration are excluded from supervisor navigation and supervisor authorization. Admins retain their existing access. Every mutation endpoint/action gets a server-side policy/authorization check; hiding navigation/actions is not authorization.

### Profile entry points

- Remove the Finance row and its profile visibility calculation only; keep the Finance panel, `finance` grants and backend rights unchanged.
- Replace Education with **Панель супервайзера** at `/admin/supervisor`, visible only when not role-previewing and `canAccessPanel()` returns true for panel `education`.
- Preserve the existing single **Мой календарь** link at `/profile/calendar` for cleaners and supervisors.
- Show **Рабочий календарь** at `/tasks/calendar` to admins and supervisors with at least one allowed non-finance general-calendar category. Preserve existing server-side category filtering and role-preview safeguards. Do not show employee calendars to guests or cleaners.
- Preserve profile styling and avoid duplicate links.

## 4. Proposed next-day celebration behavior

- Keep the existing command, service, route configuration, outbound monitor and journal.
- Change the command's implicit target to tomorrow in Europe/Rome. Explicit `--date=YYYY-MM-DD` remains available for dry-run preview of any valid date. A real send is allowed only when the requested date equals tomorrow in Europe/Rome; a seven-day window remains preview-only.
- At 06:00 Europe/Rome, the existing calendar digest remains unchanged. At 06:05 Europe/Rome, run celebrations with `withoutOverlapping()`.
- For a non-empty set of remaining events, keep the existing grouped HTML format and legacy per-event dedupe.
- When the source event collection is truly empty (count zero before legacy filtering), send exactly:

  ```text
  📅 События на DD.MM.YYYY

  ℹ️ На завтра событий нет.
  ```

  Render the requested target date in the header. If source events existed but all were already handled by legacy operation records, show that in preview and do not send a false empty-day message.
- Both normal and empty messages use one daily `TelegramOutboundOperation` keyed by date and configured destination. Reserve atomically before sending; accept delivery only with a numeric Telegram receipt. Do not resend if the operation is sent, pending, failed or uncertain. Missing route/journal, oversized content, or absent receipt fails closed.
- Keep birthday/anniversary per-event operation keys and legacy personal-event dedupe. Reuse the current daily digest key so an earlier record using that same key suppresses a duplicate. Since no production ledger was inspected, do not assume or claim any differently-keyed manual delivery is covered.
- A `failed` operation currently cannot be retried through the command; preserve this fail-closed behavior. A future retry feature would need an explicit, separately reviewed state transition. An uncertain request must never be automatically resent.
- Dry-run must not reserve outbound operations or make Telegram requests. Keep explicit dates and 1/7-day preview modes.
- No changes to the 06:00 calendar digest, other Telegram messages, or celebration route settings.

## 5. Employee work-calendar migration compatibility

No migrations are added or reordered by this work. Existing prerequisites are:

1. `2026_11_04_160000_create_employee_work_schedule_versions_table.php`
2. `2026_11_04_160001_create_employee_calendar_adjustments_table.php`
3. `2026_11_04_160002_create_user_calendar_permissions_table.php`

The first migration also writes activation snapshots for eligible staff. New code must not expose work-calendar UI before these tables exist. Deployment instructions will require verifying database identity and migration status, applying the migrations in order before activating code that uses them, then smoke-checking read-only panel/report routes. The down methods intentionally refuse to remove populated history/permission tables; deployment guidance must preserve those records during rollback. No migrations or database commands will run against production during this task.

## 6. Test design

Use isolated Laravel/Pest databases, fake Telegram transport, and synthetic users/events only.

### Panel and profile
- Approved supervisor with an existing `education` grant can open the renamed panel; no grant is added.
- Approved cleaner without a grant is denied; admin remains admitted; non-approved users are denied.
- Old base and nested links redirect only after panel authorization; denied users cannot use the alias to bypass access; old admin edit links resolve through the admin-only UserResource.
- Supervisor direct routes cannot create/edit users, change role/access, edit Control definitions/results, manage apartment grants, see Finance/global admin/system resources, or see finance-category calendar events, including users with legacy finance category grants.
- Request actions are server-authorized for supervisor/admin and rejected for cleaner/unauthorized actors.
- Staff list is limited to active approved cleaner/supervisor; personal/general calendar links reflect roles and non-finance permissions; guests/role preview do not get privileged links.

### Celebrations
- Default date is tomorrow in Europe/Rome, including UTC/Rome day-boundary cases; explicit preview date works.
- Real delivery accepts tomorrow only; arbitrary historical/future dates and seven-day delivery are refused before journal/network access.
- A truly empty tomorrow sends the exact empty digest once. A second invocation does not send again.
- Non-empty categories and legacy per-event filtering remain unchanged; if every source event was already handled, no fake empty digest is sent.
- Delivery uses the configured celebrations route, operation key, and confirmed Telegram message ID. Missing/uncertain/rejected receipt cannot be reported as sent or repeated.
- Schedule tests assert both 06:00 and 06:05 Europe/Rome and no overlap on celebrations. Existing calendar schedule is unchanged.
- Dry-run has no journal writes and no transport calls.

## 7. Verification and release boundaries

Implementation is complete only after targeted tests, PHP syntax checks, Pint, `git diff --check`, and relevant application tests pass. Any pre-existing discovery/suite failure is reported separately.

Expected changed areas are panel provider/legacy routing, curated staff surface and server-side action authorization, profile, notification URL builder, celebration command/schedule/tests, with no schema or unrelated digest changes.

No production database writes, migrations, queue/scheduler execution, real Telegram sends, deployment, merge or force push are part of this design. The requested implementation commit, branch push and PR remain the only intended external Git actions.

## 8. Framework check

Filament 5 resource/page/action authorization and legacy route behavior must be checked against installed APIs before coding. Prefer existing policies/services and explicit allowlists. If a desired supervisor action lacks an existing service-level authorization boundary, add a narrowly scoped server-side check around that action rather than relying on Filament visibility.
