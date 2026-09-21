# Operational safety

This file classifies actions, not environments. If the target or side effect is unclear, move the action to the stricter class.

## GREEN — safe autonomous checks

- read source, config files, documentation, migrations, and tests (never print `.env`);
- `git status`, `git diff`, `git diff --check`, and read-only history inspection;
- targeted or full automated tests that use the configured in-memory test databases and fake external transport;
- Pint and Vite build;
- `php artisan about`, code discovery, and explicitly read-only preview commands when their implementation has been inspected;
- create or edit files inside the requested repository scope.

Do not paste personal data or Telegram identifiers from preview output into reports.

## YELLOW — explicit approval required

- any migration or schema command, including analytics migrations;
- operational replay/catch-up, because it writes observations, events, and evidence;
- queue workers/listeners, failed-job retry, scheduler commands, or long-running local services;
- seed/import/backfill commands or any command whose database target is inherited from `.env`;
- writes to a local production copy, uploads, runtime storage, or external service;
- Telegram delivery dry-runs until the command path has been confirmed not to call transport;
- dependency updates, destructive Git operations, or changes to environment/config values.

Before approval, state the exact command, target connection/environment, expected writes, bounds, and rollback/recovery path.

## RED — never autonomous

- production migrations, destructive SQL, database replacement, or modification of production copies;
- real Telegram sends, external notifications, deploys, worker/scheduler restarts, or production feature-flag changes;
- secret/token disclosure or `.env` edits;
- bypassing roles, policies, approval gates, sandboxing, or transport safety;
- push/force-push, destructive Git cleanup, or deletion of user data unless the user explicitly requests the exact action and all required safeguards are satisfied.

Treat a request for code implementation as no authorization for a RED action.

## Database map and checks

- Primary connection: normal application models and the database queue.
- Analytics connection: Telegram chats, topics, users, messages, attachments, assistant data, operational observations/events/evidence.
- Pest primary DB: SQLite `:memory:` from `phpunit.xml`.
- Telegram analytics tests: must override `database.connections.analytics.database` to `:memory:` before opening the connection and purge it afterward.

Never run `migrate`, replay, import, or seed merely to validate bootstrap. Inspect the command/migration first and name the connection explicitly when an approved operation is eventually run.

## Current operational caveats

- `config/database.php` reads `DB_ANALYTICS_DATABASE`, while `.env.example` currently advertises `ANALYTICS_DB_DATABASE`. Resolve this in a separately scoped configuration task; do not guess or edit a live environment.
- Queue defaults to the database driver locally; `composer dev` starts a live queue listener.
- Operational observer and evening delivery have feature flags, but a disabled flag is not permission to run delivery-capable commands.
- No CI workflow is present, so FULL VERIFY must be run and reported locally before release-sensitive changes.
