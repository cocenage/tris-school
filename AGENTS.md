# TRIS Academy — Agent Instructions

This repository is TRIS Academy, an internal Laravel application for staff and supervisors.

These rules apply to every AI coding task in this repository unless the user explicitly overrides them.

## Stack

- PHP ^8.2
- Laravel 12.56
- Filament 5.4
- Livewire 4.2
- Blade + Livewire Volt
- Tailwind CSS 4
- Alpine.js 3
- Pest 4

Do not use APIs or examples intended for old Filament / Livewire versions without verifying compatibility with the installed versions.

## Project map

Important areas:

- `app/Models` — Eloquent models
- `app/Services` — domain/business services
- `app/Jobs` — queued jobs
- `app/Console/Commands` — Artisan commands
- `app/Filament` — Filament resources/pages
- `app/Policies` — authorization
- `resources/views/components` — user-facing Livewire Volt components
- `resources/views/layouts` — application layout
- `routes/web.php`
- `routes/api.php`
- `routes/console.php`
- `database/migrations`

Some Volt component filenames contain special characters such as `⚡`.

## Read project memory first

Before doing non-trivial work, read only the relevant files:

- `.ai/STATE.md`
- `.ai/DECISIONS.md`
- `.ai/WORKFLOW.md`
- `.ai/KNOWN-ISSUES.md`

Do not perform a broad repository audit unless the task actually requires one.

## Safety

Never:

- print or expose `.env` values;
- print tokens, secrets, Telegram IDs or personal data;
- modify `.env` without explicit permission;
- delete or overwrite production SQLite copies;
- modify user uploads/runtime storage unnecessarily;
- run migrations without explicit permission;
- run queue workers without explicit permission;
- run scheduler commands without explicit permission;
- send real Telegram messages without explicit permission;
- invoke commands that perform external writes without explicit permission;
- bypass server-side roles, policies or access checks.

Telegram analytics uses a separate `analytics` database connection.

Do not silently move analytics data/models to the main database.

## Scope discipline

Modify only what is necessary for the requested task.

If the task is UI-only:

- do not change database schema;
- do not change scoring;
- do not change domain rules;
- do not change authorization;
- do not change Telegram;
- do not change unrelated components.

If an unrelated bug is discovered, report it separately instead of expanding scope.

Do not start architectural refactors unless the requested task requires them.

## Existing behavior

Preserve existing:

- `wire:model` semantics;
- Livewire actions/events;
- validation;
- autosave/drafts;
- storage semantics;
- permissions;
- model relationships;
- external integrations.

Do not replace working behavior merely because another implementation looks cleaner.

## Verification rule

A task is NOT complete because:

- the code looks correct;
- unit tests pass;
- a synthetic fixture passes;
- the agent believes the fix should work.

The reported acceptance scenario itself must pass.

Follow `.ai/WORKFLOW.md`.

## UI work

For Blade / Livewire / Alpine changes:

- inspect the real component before editing;
- determine the real scroll container/layout hierarchy;
- preserve Livewire state;
- compile Blade after structural edits;
- do not make a huge Blade rewrite before checking syntax;
- use browser smoke testing when browser access is available;
- never claim visual/browser verification if it was not actually performed.

## Quality gates

Use targeted checks first.

Typical full checks:

```bash
php artisan test
vendor/bin/pint --test
npm run build
git diff --check