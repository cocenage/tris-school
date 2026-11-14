# Development and agent workflow

## Safe bootstrap

Prerequisites are PHP 8.2+, Composer 2, Node.js, and npm. On the audited host Laravel runs through Herd. Keep machine-specific executable paths out of the repository.

For an existing checkout:

```powershell
composer install
npm install
php artisan about
```

Do **not** use `composer setup` as a harmless bootstrap check: the existing script creates `.env`, generates a key, and runs migrations. Environment creation, key generation, and migration require an explicit setup task and a confirmed non-production target.

### Local SQLite on Windows and Herd

Use PowerShell from the project root. Install the locked dependencies and build the assets:

```powershell
composer install
npm ci
npm run build
```

Make sure the local Laravel configuration has an application key and points to local SQLite files. Verify the configured `DB_DATABASE` and `DB_ANALYTICS_DATABASE` paths before running schema commands. The following commands assume the default paths. Set up a new `.env` and run `php artisan key:generate` only on a fresh checkout that has no `.env` yet.

```powershell
foreach ($database in 'database/database.sqlite', 'database/telegram_analytics.sqlite') {
    if (-not (Test-Path $database)) {
        New-Item -ItemType File -Path $database -ErrorAction Stop | Out-Null
    }
}
php artisan config:clear
```

For a **new, empty analytics file in a fresh checkout**, run the base Telegram migrations against `analytics` first. These migration files predate the explicit analytics connection used by later migrations. Running them in the default connection alone leaves `analytics.telegram_topics` missing.

```powershell
$telegramMigrations = @(
    '2026_05_21_082853_create_telegram_chats_table.php',
    '2026_05_21_082854_create_telegram_topics_table.php',
    '2026_05_21_082855_create_telegram_users_table.php',
    '2026_05_21_082857_create_telegram_messages_table.php',
    '2026_05_21_082906_create_telegram_attachments_table.php',
    '2026_05_21_101806_fix_telegram_chats_table.php'
)
$analyticsPaths = $telegramMigrations | ForEach-Object { "--path=database/migrations/$_" }
php artisan migrate --database=analytics @analyticsPaths
if ($LASTEXITCODE -ne 0) { throw 'Analytics bootstrap failed' }
```

For an **existing checkout**, back up the SQLite files before upgrading. Keep the analytics file that belongs to the primary database. If it held Telegram data, restore the matching copy or point `DB_ANALYTICS_DATABASE` to it.

If the analytics file was **never used and can start empty**, create it and run the base Telegram migrations above. Then check `php artisan migrate:status --database=sqlite`. For each migration in the list below marked **Ran** in the primary ledger, rebuild its analytics schema by passing its path to `php artisan migrate --database=analytics --path=database/migrations/<filename>` in the listed order. Leave the **Pending** migrations for the primary migration command. This restores the local schema only.

- `2026_07_31_161000_create_telegram_assistant_requests_tables.php`
- `2026_09_16_000000_create_telegram_operational_event_ledger.php`
- `2026_09_20_000000_add_apartment_context_to_telegram_operational_events.php`
- `2026_11_01_000000_add_source_bot_to_telegram_operational_observations.php`

Once the analytics database is ready, run the pending primary migrations. This also runs analytics changes that explicitly select that connection. The primary schema includes `coachings`, which the profile activity pages query.

```powershell
php artisan migrate --database=sqlite
php artisan migrate:status --database=sqlite
```

For a smoke test, sign in with local approved test accounts. Check **Профиль → Мой календарь** as a cleaner and **Профиль → Календари сотрудников** as an administrator. Keep both SQLite files when switching branches or updating the checkout.

Run development processes only when requested:

```powershell
composer dev
```

That command starts the HTTP server, a queue listener, and Vite, so it is approval-required under the repository safety rules.

## Task flow

1. Capture the acceptance scenario and constraints.
2. Inspect relevant project memory and the narrow dependency chain.
3. Reproduce before editing when possible.
4. Make the smallest coherent change and add focused coverage.
5. Rerun the exact scenario.
6. Run FAST VERIFY; use FULL VERIFY for broad, shared, release, or explicitly requested changes.
7. Report checks, side effects, changed files, and gaps.

Spec Kit already exists under `.specify`, `specs`, and `.agents/skills`. Start a Spec Kit cycle only when the user asks for it; ordinary bounded tasks use the flow above.

## FAST VERIFY

FAST VERIFY is the default inner loop. First run the exact targeted test or command named by the task, then:

```powershell
php artisan test --testsuite=Unit
git diff --check
```

Add only gates relevant to changed files:

```powershell
vendor/bin/pint --test path/to/changed.php
npm run build
```

- Run scoped Pint for changed PHP files.
- Run the Vite build for Blade, Livewire, Alpine, Tailwind, JavaScript, or asset changes.
- A real browser acceptance scenario is required for interaction/layout bugs when browser access exists.
- A domain feature test is not replaced by the Unit suite; its targeted acceptance test comes first.

## FULL VERIFY

FULL VERIFY is the local release/broad-regression gate:

```powershell
php artisan test
vendor/bin/pint --test
npm run build
git diff --check
```

Use it before merge for shared services, providers, routing, authorization, migrations, cross-cutting UI, dependency changes, or when requested. It may be long, but failures must be reported; do not relax a gate or fix unrelated code merely to make it green.

## Codex CLI: confirmed native capabilities

Audited with `codex-cli 0.155.0-alpha.9.2`. Recheck `--help` after upgrades.

```powershell
codex --version
codex doctor --summary
codex -C . --sandbox workspace-write --ask-for-approval on-request
codex exec -C . --sandbox workspace-write "<bounded task>"
codex exec --json -C . "<bounded task>"
codex exec resume --last "<follow-up>"
codex resume --last
codex fork --last --worktree
codex agents
```

Confirmed native features include interactive and non-interactive sessions, JSONL event output, final-message files, output JSON schemas, session resume/fork, managed worktrees, session browsing, sandbox selection, and diagnostics. Prefer them over custom runners or session managers.

The inspected help does not expose an authoritative monetary-cost or token-budget report. Do not invent one. Record elapsed work, checks, and concrete outputs; use product-provided usage UI when budget information is required.

Never use `--dangerously-bypass-approvals-and-sandbox` for routine repository work.
