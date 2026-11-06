# Supervisor Panel and Daily Events Digest — Implementation Plan

**Date:** 2026-11-06  
**Branch:** `codex/supervisor-panel-events-digest`  
**Base:** `origin/main` at `d95aa73a1ae1734650551020eeb8a2bd9abdf214`  
**Approved design:** `docs/superpowers/specs/2026-11-06-supervisor-panel-events-digest-design.md`

## Constraints

- Keep panel ID `education` and all current access rows; do not grant new users access.
- No production DB, .env, queue, scheduler execution, Telegram delivery, deployment, merge, force-push, or data changes.
- Do not alter celebration/calendar route configuration, other Telegram message text, quality-control scoring, finance rights, or unrelated worktrees.
- Use isolated Pest databases and fake transport. New server authorization must be tested by direct route/action access, not only navigation visibility.
- No schema migration is planned. Runtime work-calendar surfaces require existing tables from migrations 160000, 160001, 160002 in timestamp order.

## Ordered work

### 1. Establish targeted test fixtures and baseline

Inspect existing Pest setup/helpers for roles, Filament panel routes, request fixtures, calendar permissions, and Telegram outbound operations. Add/adjust focused tests before implementation for:
- tomorrow default/date validation, empty-day send, legacy-filtered no-send, duplicate suppression, confirmed receipt, dry-run, route, timezone and schedule;
- panel entry with explicit grants, legacy redirect authorization, supervisor resource/action boundaries, profile links and general-calendar category filtering;
- migration/table assumptions using the existing isolated migration tests.

Run the focused files before implementation to record the current expected failures. Do not run migration commands against a configured non-test database.

### 2. Implement next-day celebration behavior

Files:
- `app/Console/Commands/SendTeamCelebrationsNotification.php`
- `routes/console.php`
- `tests/Feature/TeamCelebrationsNotificationTest.php`
- `tests/Feature/TeamCelebrationServiceTest.php` only if renderer coverage needs a focused addition.

Steps:
1. Make the default target tomorrow in Europe/Rome; retain exact `--date` validation and arbitrary dry-run preview. Restrict real delivery to tomorrow and keep seven-day preview-only behavior.
2. Render the exact empty-day text only when source event count is zero before legacy event filtering. If every event is filtered as already handled, skip delivery.
3. Send empty and populated digests through the same route, date+destination operation key, atomic `reserveExpected()`, monitor context, size guard and confirmed receipt path.
4. Keep existing per-event dedupe. Preserve fail-closed behavior for sent/pending/failed/uncertain operations; do not add automatic retries or transport fallback.
5. Move only the celebrations schedule to 06:05 Europe/Rome with no overlap; keep calendar digest at 06:00 unchanged.
6. Run celebration and outbound-monitor feature tests plus scoped PHP lint/Pint.

### 3. Add a centralized supervisor authorization boundary

Files expected:
- new narrowly scoped authorization helper or policies under `app/Policies` / `app/Services` only if current policies cannot express the approved matrix;
- `app/Providers/Filament/EducationPanelProvider.php`;
- `routes/web.php`;
- tests in a focused new `tests/Feature/SupervisorPanelAccessTest.php` or existing equivalent.

Implement approved-supervisor + explicit `education` checks for supervisor-only actions while preserving admin behavior. Keep panel entry itself governed by existing `User::canAccessPanel()`. Add an authenticated GET/HEAD legacy route using a wildcard path constraint; authorize before redirecting and never redirect to a caller-supplied host. Keep `UserResource` available to admins for existing nested links and deny supervisor access to its identity/access editing and sensitive detail pages.

### 4. Build the panel from an explicit allowlist

Register only the resources/pages needed for the four approved navigation groups. Avoid broad resource discovery.

Expected surfaces:
- a curated, read-only staff view over `User`, restricted to approved active cleaners/supervisors and safe fields;
- the existing work-calendar report and relation managers guarded by `canViewAllEmployeeCalendars()`, `canEditEmployeeCalendars()`, `EmployeeWorkCalendarManagementService`, and `canExportEmployeeCalendars()`;
- existing day-off and vacation request views/actions, with server-side authorization at the action/page boundary and validation of allowed state changes;
- existing Control/ControlResponse read-only views (preserving saved comments, corrective fields, media and score display) with config/result mutation denied to supervisors;
- apartments through `ApartmentAccessService` visible query, tasks through authenticated existing routes and `Task::visibleFor()`, read-only instructions, and calendar events restricted to allowed categories with `finance` excluded for supervisors without changing stored grants.

Add direct-access regression tests for every forbidden write surface and finance/global-admin/system route. Keep apartment grants, User roles/access, Telegram administration and Finance unavailable to supervisors.

### 5. Update profile/calendar entry points and old links

Files:
- `resources/views/components/⚡page-profile.blade.php`
- `resources/views/components/tasks/⚡page-calendar.blade.php` (or its narrow authorization helper)
- `app/Services/TelegramUserNotificationService.php`
- focused profile/route tests.

Remove only the Finance profile link; keep Finance functionality and access rows untouched. Replace Education with the new supervisor label/path gated by the real panel access check. Preserve one personal calendar link for cleaners/supervisors. Show the general calendar for admins and supervisors with at least one permitted non-finance category. Keep guests, cleaners and role-preview users out of other employees' calendar data. Generate future admin links under `/admin/supervisor`; verify old nested admin edit URLs redirect and still resolve.

Compile Blade and run browser smoke testing if browser access is available.

### 6. Verify migrations and operational notes

- Inspect and test the existing migration files; do not edit them unless a concrete dependency defect is demonstrated.
- Confirm the required order: `160000` schedule versions (including activation snapshots), `160001` calendar adjustments, `160002` user calendar permissions.
- Add a deployment note to the final report: verify DB identity/status, apply these migrations in order before activating code using employee calendar tables, smoke-check read-only routes, and preserve populated history/permissions on rollback. No production migration is executed in this task.

### 7. Full targeted verification and delivery

Run:
- targeted Pest tests for celebrations, outbound monitor, panel/profile authorization, requests, controls, apartments, instructions, tasks/calendar and work-calendar;
- PHP syntax checks for changed PHP files;
- scoped `vendor/bin/pint --test`;
- Blade compilation/build for changed Blade/Livewire surfaces and browser smoke when available;
- `git diff --check`.

Review changed-file list and the final diff for unrelated behavior. Commit on the feature branch, push the branch, and create a PR against `main`. Attach the PR in Codex if the connector supports it. Do not merge or deploy. Report any blocked push/PR separately from a successful commit.

## Completion evidence

The final report must separate:
1. branch/base SHA, commit SHA, push status and PR status;
2. tests/format/build checks and any pre-existing failures;
3. migration order and safe deployment sequence;
4. confirmation that production, real Telegram delivery, queues and scheduler were not touched.
