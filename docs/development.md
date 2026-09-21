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
