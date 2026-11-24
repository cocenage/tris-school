# TRIS Academy: production release preparation

**Scope:** safely update an existing shared-hosting checkout to a selected GitHub `main` release, including the matching Vue/Vite assets. This document is operational guidance; pushing a PR and passing CI are **not** a production deploy.

## Verified starting point (25 Nov 2026)

- Reported server checkout: `8d55d74c3cb1af24c8751b9e0eb13dc1592c5348`.
- Release preparation started from `972eeb342361ea7f09f3a8fd0d1bd9d420bf6506`. **Do not use this hard-coded SHA for a later release**: read the actual SHA from the release artifact.
- Diff to that preparation point: 117 commits, 40 changed paths; no changed migration files, composer lock, or npm lock. **Existing Pending migrations must still be inspected.**
- PHP CLI on the reported server: `/opt/php/8.4/bin/php`.
- Scheduler is configured in crontab. Its existence is not proof each notification was delivered.
- `TELEGRAM_OKS_CURRENT_CONTROLS_ENABLED=true`, and recent deliveries use copy version 2. The visible rich-card heading correction is in GitHub PR #182.
- `calendar:notify-celebrations` now intentionally sends a no-events daily message. A real delivery cannot be tested with a past date.
- Do **not** run `npm ci` or `npm run build` on the shared host. Use the commit-matched GitHub Actions release artifact.

## 1. Get the exact-matching release bundle

GitHub → repository `cocenage/tris-school` → **Actions → Academy release assets** → latest successful **push to main** run (or manually run this workflow on main).

Download the artifact named `academy-release-<full-git-sha>`. It contains:

- `academy-vite.tar.gz` — contents of `public/build/`, compressed with the leading `build/` directory;
- `release-commit.txt` — commit SHA of this build;
- `SHA256SUMS` — checksum for the tarball.

Unzip the artifact locally and upload those three files to a **private, non-web-accessible** folder on the server, e.g. `$HOME/academy-release/`. Do not upload the bundle to `public/` or serve it via HTTP.

On the server:

```bash
APP=/var/www/u2952306/data/www/academy.trisservice.eu
PHP84=/opt/php/8.4/bin/php
RELEASE_DIR="$HOME/academy-release"
cd "$RELEASE_DIR"
sha256sum -c SHA256SUMS
test -s release-commit.txt
RELEASE_SHA="$(tr -d '\r\n' < release-commit.txt)"
case "$RELEASE_SHA" in *[!0123456789abcdef]*|'') echo 'Bad release SHA'; exit 1;; esac
test "${#RELEASE_SHA}" -eq 40 || exit 1
printf 'Release SHA: %s\n' "$RELEASE_SHA"
```

Do not proceed if the checksum fails.

## 2. Production read-only preflight (before any write)

```bash
cd /var/www/u2952306/data/www/academy.trisservice.eu
git status --short
git rev-parse --verify HEAD
git fetch origin main
git merge-base --is-ancestor HEAD "$RELEASE_SHA" || { echo 'STOP: server cannot fast-forward to this release'; exit 1; }
git cat-file -e "$RELEASE_SHA^{commit}" || exit 1
"$PHP84" artisan migrate:status
"$PHP84" artisan schedule:list
df -h .
```

Check that tracked files have **no local modifications**. If any unexpected Pending migrations appear, stop and inspect their target database and impact. Never substitute a different DB. If the server has locally edited files, do not reset or overwrite them.

Once the checkout contains the release scripts, `bash scripts/release/academy-preflight.sh` provides a second read-only check.

## 3. Mandatory backups BEFORE deployment

Back up and verify recoverability of **all** of:

1. Primary production database (identify `DB_CONNECTION` and configured database without printing credentials). For MySQL/Postgres, use an approved consistent transactional database dump. For SQLite, use a consistent SQLite backup (`sqlite3 .backup` or host/provider snapshot); copying a live DB alone is unsafe if WAL/writers are active.
2. Separate `analytics` database, including Telegram chats/topics/events, if configured.
3. `storage/app` and any separately configured user uploads/media, plus any publicly stored files.
4. `.env` (private permissions, outside web root; never paste it into chat).
5. Current `public/build` and current code SHA.

Coordinate queue workers and the cron scheduler so the database backup is consistent. An application maintenance page **does not automatically stop** cron jobs, queue workers or Telegram webhook senders. Verify backup files exist and can be restored. If backup or quiescence is not assured, **STOP**.

Example for the code SHA and existing Vite build, after arranging a private backup directory:

```bash
BACKUP_DIR="$HOME/academy-backups/$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 -p "$BACKUP_DIR"
cd "$APP"
git rev-parse HEAD > "$BACKUP_DIR/previous-sha.txt"
tar -C public -czf "$BACKUP_DIR/public-build.tar.gz" build
test -s "$BACKUP_DIR/public-build.tar.gz" || exit 1
# Back up databases, uploads and .env separately before proceeding.
```

**Do not treat the two lines above as a full backup.** They only protect the previous Git pointer and build files.

## 4. Controlled deploy — only after verified backups

Extract the release tarball into a separate staging folder first. Check it contains `build/manifest.json` and `build/assets/`.

```bash
mkdir -p "$RELEASE_DIR/unpacked"
tar -xzf "$RELEASE_DIR/academy-vite.tar.gz" -C "$RELEASE_DIR/unpacked"
test -s "$RELEASE_DIR/unpacked/build/manifest.json" || exit 1
test -d "$RELEASE_DIR/unpacked/build/assets" || exit 1
cd "$APP"
git merge-base --is-ancestor HEAD "$RELEASE_SHA" || exit 1
git status --porcelain --untracked-files=no
```

If all checks are clean and the maintenance window has been established:

```bash
"$PHP84" artisan down --retry=60
git merge --ff-only "$RELEASE_SHA"
test "$(git rev-parse HEAD)" = "$RELEASE_SHA" || exit 1
mkdir -p public/build/assets
cp -a "$RELEASE_DIR/unpacked/build/assets/." public/build/assets/
# Publish the Vite manifest LAST, after every referenced asset is in place.
cp -a "$RELEASE_DIR/unpacked/build/manifest.json" public/build/manifest.json
"$PHP84" artisan optimize:clear
"$PHP84" artisan queue:restart
"$PHP84" artisan up
```

Do **not** automatically run `migrate --force`, `composer update`, `npm`, seeders, or Telegram send commands. After checkout, inspect migration status again. If a new schema is unexpectedly required, pause the rollout and assess it separately; restored backups are the rollback point.

If an error happens **after** `artisan down`, do not leave the site half-updated: use the rollback procedure and inspect it before `artisan up`.

## 5. Acceptance checks (no real Telegram sends)

```bash
cd "$APP"
git rev-parse --short HEAD
"$PHP84" artisan route:list --path=checks/control/focus-v2
"$PHP84" artisan route:list --path=antigoccia
"$PHP84" artisan migrate:status
"$PHP84" artisan calendar:notify-celebrations --dry-run
"$PHP84" artisan tinker --execute='
$d = \App\Models\TelegramScheduledMessageDelivery::query()
  ->where("control_type", "oks_first_finish")->latest("id")->first();
echo $d ? \App\Services\Telegram\OksControlSchedule::headingFor($d).PHP_EOL : "No recent OKS record".PHP_EOL;
'
```

Use an approved test account in a real browser/Telegram WebView to verify Focus Control V2 (including media recovery), ANTIGOCCIA's independent four-way responses and own-answer deletion, admin analytics, and new Telegram OKS headings on **new** deliveries. Already sent Telegram messages cannot be retroactively changed by deploying code.

The empty calendar message is sent by the scheduler at 06:05 **Europe/Rome** the next scheduled morning; `--dry-run` never sends. Review the `telegram_outbound_operations` receipt status and cron logs after that run. Do not manually repeat an `uncertain` message until confirmed.

## 6. Safe rollback plan

Because the currently reviewed Git difference contains **no new migration files or dependency lock changes**, the first rollback strategy is old PHP code **plus the old build**. Only use it when no schema/data migration was applied and backups are verified.

```bash
cd "$APP"
"$PHP84" artisan down --retry=60
OLD_SHA="$(cat "$BACKUP_DIR/previous-sha.txt")"
git switch --detach "$OLD_SHA"  # avoids force-resetting a branch
tar -C public -xzf "$BACKUP_DIR/public-build.tar.gz"
"$PHP84" artisan optimize:clear
"$PHP84" artisan queue:restart
"$PHP84" artisan up
```

This leaves the checkout at a **detached HEAD**. A subsequent upgrade must first explicitly return to the intended branch; do not casually `git pull` from detached HEAD. If migrations or data writes occurred, a code-only rollback may be insufficient: coordinate restoring the matching database backups. Never delete uploads or run schema rollbacks blindly.

## Stop conditions

- The SHA in `release-commit.txt` does not match the intended Git commit.
- Missing or inconsistent database/media backups, insufficient storage, or active writers without a safe backup method.
- Dirty tracked working tree, non-fast-forward Git history, failed checksum/build verification.
- Unexpected Pending migrations or incompatible DB connection.
- Old build not preserved or release manifest missing.
- Site or Telegram WebView fails acceptance checks after deployment.

**No server-side changes are made by creating this release kit.** Production deployment requires an explicit operator maintenance window and verified backups.
