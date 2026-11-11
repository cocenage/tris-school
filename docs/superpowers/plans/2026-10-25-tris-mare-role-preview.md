# TRIS Mare Retirement and Role Preview Implementation Plan

> **For agentic workers:** Implement task-by-task with the repository's existing Laravel, Livewire, Alpine, and Tailwind patterns; keep the two features in separate commits.

**Goal:** Remove TRIS Mare from client-facing and scheduled surfaces, then give real administrators a session-only, read-only UI preview for supervisor and cleaner roles.

**Architecture:** The TRIS Mare change removes its profile renderer/query, its scheduled sync, and the legacy Filament leaderboard entry while retaining historical data models and operational-context reads. Role preview stores a validated role in the authenticated admin's session, exposes a separate effective UI role for presentation, keeps actual authorization unchanged, and blocks specific write actions at their server entry points.

**Tech Stack:** Laravel 12, Livewire 4/Volt, Filament 5, Blade, Pest 4.

**Spec:** User attachment `Pasted text.txt` supplied in the task.

## Global Constraints

- Preserve `users.role`, Auth identity, policies, route guards, and Filament authorization.
- No migrations, data changes, Telegram sends, or production changes.
- Keep TRIS Mare models, tables, migrations, historical data, and operational context.
- Do not create role-specific navigation differences that do not exist today.
- Use only session state for the preview; it expires with the session.

## Review Focus

- A non-admin cannot activate or clear preview by forging an HTTP or Livewire request.
- A preview request cannot invoke a real write even when the UI control is called directly.
- Livewire navigation and property updates continue to work during preview.
- Cleaner/supervisor previews do not reveal staff-specific records from the administrator's account or other employees.
- Filament continues to use the real administrator identity and permissions.

---

### Task 1: Retire TRIS Mare surfaces

**Files:**
- Modify `resources/views/components/⚡page-profile.blade.php`.
- Modify `routes/console.php`.
- Modify `app/Filament/Resources/RewardPrograms/Pages/EditRewardProgram.php`.
- Delete `app/Filament/Widgets/RewardProgramLeaderboardWidget.php` and `resources/views/filament/widgets/reward-program-leaderboard-widget.blade.php` after confirming no other consumers.
- Test `tests/Feature/TrisMareRetirementTest.php`.

**Acceptance:** profile no longer queries or renders TRIS Mare; schedule omits `tris-mare:sync` while other schedules remain; Filament edit page no longer mounts the legacy leaderboard; historical storage and `OperationalContextBuilder` remain unchanged.

### Task 2: Add a session-only role preview

**Files:**
- Create `app/Services/RolePreviewContext.php` with `actualRole()`, `previewRole()`, `effectiveUiRole()`, `isActive()`, `start(string $role)`, and `clear()`.
- Modify `resources/views/components/⚡page-profile.blade.php` to show an actual-admin-only role selector and call server-validated actions.
- Modify `resources/views/layouts/app.blade.php` for the preview banner and exit control.
- Add the narrow server-side preview action guard and apply it to persistence actions in client Volt pages and HTTP controllers; do not reject normal Livewire update requests.
- Update only presentation decisions in the shared navbar and role-sensitive client pages; do not change authorization checks.
- Add focused tests under `tests/Feature/RolePreviewTest.php` for roles, session persistence, forbidden forged requests, write blocking, safe page data, and unchanged Filament access.

**Acceptance:** actual and UI roles remain distinct; only real admins can set the session preview; the banner survives client navigation; mutations remain blocked at the server; user and Filament permissions remain unchanged.

### Task 3: Verify and release locally

- Run the two focused Pest files with the isolated in-memory database.
- Run Pint on changed PHP files and `git diff --check`.
- Commit Task 1 and Task 2 separately, push the working branch, and open a PR; do not deploy.
- If the current PHPUnit bootstrap path remains unreadable, report that exact infrastructure blocker and do not claim the corresponding tests passed.
