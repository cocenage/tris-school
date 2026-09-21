# TRIS Academy — Agent Instructions

TRIS Academy is a mature internal Laravel application for staff and supervisors. Preserve existing behavior and make the smallest coherent change.

## Stack

- PHP 8.2+ (local runtime currently reports PHP 8.4)
- Laravel 12, Filament 5, Livewire 4/Volt
- Tailwind CSS 4, Alpine.js 3, Vite 7
- Pest 4

Verify examples against the installed major versions; do not copy patterns from older Filament or Livewire releases.

## Project map

- `app/Models`, `app/Services`, `app/Jobs`, `app/Console/Commands` — domain and background work
- `app/Filament` — Admin, Education, and Finance panels
- `resources/views/components` — user-facing Volt components; some filenames contain `⚡`
- `routes/web.php`, `routes/api.php`, `routes/console.php` — HTTP and scheduled entry points
- `database/migrations` — primary and explicitly connection-scoped migrations
- `tests/Unit`, `tests/Feature`, `tests/Support` — Pest suites and isolated fixtures
- `specs`, `.specify`, `.agents/skills` — existing Spec Kit artifacts; use only when requested
- `docs` — architecture, development, and operational safety notes

See [docs/architecture.md](docs/architecture.md), [docs/development.md](docs/development.md), and [docs/operations.md](docs/operations.md).

## Before editing

1. Read the request and only the directly relevant code.
2. Read the relevant parts of `.ai/STATE.md`, `.ai/DECISIONS.md`, `.ai/WORKFLOW.md`, and `.ai/KNOWN-ISSUES.md`.
3. Run `git status --short`; preserve unrelated and user-owned changes.
4. Reproduce the exact acceptance scenario when technically possible.

Do not perform a repository-wide audit or start a new Spec Kit cycle unless the task explicitly requires it.

## Safety

- Never print or modify `.env`, tokens, Telegram IDs, personal data, or production data.
- Telegram analytics uses the separate `analytics` connection. Never silently move it to the primary database.
- Do not run migrations, replay commands that write the ledger, queue workers, scheduler commands, real Telegram sends, deploys, pushes, or external writes without explicit permission.
- Do not delete or overwrite database copies, uploads, or runtime storage.
- Preserve roles, policies, server-side authorization, model relationships, Livewire state, storage semantics, and integrations.
- Treat any unclear database or external side effect as approval-required. The GREEN/YELLOW/RED matrix is in [docs/operations.md](docs/operations.md).

## Working loop

Understand → reproduce → trace the failing dependency chain → make the smallest fix → rerun the same scenario → add focused regression coverage → run verification.

Report unrelated defects instead of fixing them opportunistically. Do not replace working architecture merely because another design looks cleaner.

## Verification

Run the task's exact acceptance scenario first, then the repository verification level appropriate to the risk. Commands and selection rules are in [docs/development.md](docs/development.md).

For Blade, Livewire, or Alpine work, compile after structural edits and perform browser smoke testing when browser access is available. Never claim browser verification that was not performed.

## Definition of Done

A task is done only when:

- the requested acceptance scenario passes;
- relevant regression tests pass;
- formatting/build checks required by the changed scope pass;
- `git diff --check` passes;
- no unrelated files or behavior were changed;
- database, queue, scheduler, Telegram, and deployment side effects are disclosed;
- the final report lists verification performed and any remaining gap.

A code review, synthetic fixture, or green unit test alone is not completion when the reported real scenario can be exercised.
