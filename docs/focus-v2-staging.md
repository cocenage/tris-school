# Focus Control V2 — isolated staging rollout

## Deployment status

This describes an **isolated staging environment** for testing and benchmarking.
The Vue 3 release is now part of the main branch: for production setup and
rollback see [Focus V2 production rollout](focus-v2-production-rollout.md).
Staging continues to require its own HTTPS host, DB, test bot and storage.

The Focus V2 CI run at
https://github.com/cocenage/tris-school/actions/runs/45114440899
passed Composer installation, `npm ci`, Vite compilation, and the
ControlWorkflowTest suite using in-memory SQLite. It also exported the
`focus-v2-vite-build` artifact for five days.

A successful GitHub Actions workflow provides a tested `public/build/`
artifact (`focus-v2-vite-build`), but **does not publish the application
or create a staging server**. The built assets must be deployed with the
matching commit of Laravel source. Mixing different commits is unsupported.

## Hard safety gates

- Dedicated HTTPS host and dedicated staging application root/document
  root; do not point it at the production Academy.
- Dedicated DB, credentials and storage. Do not copy a production DB
  containing personal data unless its owner explicitly approves the
  transfer and the data is appropriately sanitized.
- No production Telegram bot token, webhook, queue worker, scheduler, chat
  IDs or outbound integrations. Set up a separate test bot if testing
  inside a Telegram WebView. Otherwise use staging test-account sessions.
- Never use the production `.env` on staging.
- **V2 feature flag:** the public release defaults to enabled (unless overridden).
  Set `FOCUS_V2_ENABLED=true` explicitly on staging to document the intended
  test configuration. Use `FOCUS_V2_ENABLED=false` for a rollback:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<your-staging-host>
FOCUS_V2_ENABLED=true

# Set these to real dedicated staging database credentials.
# DB_CONNECTION, DB_DATABASE, DB_USERNAME, DB_PASSWORD must NOT
# resolve to production. Keep APP_KEY / SESSION_COOKIE separate.
QUEUE_CONNECTION=sync
MAIL_MAILER=log
```

Avoid running real cron, background jobs or Telegram notifications until
a separate non-production bot and outbound config have been verified.
The staging host needs adequate storage for both upload chunks and final
media: large media batches can temporarily consume nearly twice the
file's size during reassembly.

## Install on staging only

1. Create the isolated staging server/database and test users.
2. Fetch the branch at the precise SHA that passed CI:

```bash
git fetch origin
git switch codex/focus-control-v2-foundation
git pull --ff-only origin codex/focus-control-v2-foundation
```

3. Configure the staging-only environment, ensuring `APP_URL`,
   database, disk and session settings cannot reach production.
4. On this **isolated** environment only:

```bash
composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
npm ci
npm run build
php artisan config:clear
php artisan focus-v2:staging-check
php artisan migrate --force
php artisan route:clear
php artisan view:clear
```

You can use the `focus-v2-vite-build` artifact from the matching CI run
instead of doing the Vite build on the staging host. Do not forget that
the PHP source still needs deployment, and do not overwrite the
production `public/build`.

5. From a logged-in active supervisor/admin staging account, visit:

```text
https://<your-staging-host>/checks/control/focus-v2
```

The old Focus route stays available on staging and in production. The
production rollout is permitted for authorized staff, and can be disabled
immediately with `FOCUS_V2_ENABLED=false`; in that case all V2 pages and
API endpoints return 404.

The read-only `focus-v2:staging-check` command must succeed **before**
database migrations or large-media acceptance testing. It checks a staging
runtime, HTTPS on a non-production hostname, an enabled preview flag, disabled
debugging, an app key, a configured non-memory database, queue sync and mail log.
The checker cannot prove that database/storage, Telegram tokens and chat IDs
are different from production; a human must inspect those separately. No
database migrations, writes or outbound messages are performed by the check.

## Mobile acceptance sequence

- Check setup and answer save, refresh, and score parity.
- Upload 10 images, then 100 images spread across questions, then 200+
  images. Current limit is 12 attachments **per question**, not per run.
- Add videos including a large MP4/MOV within 100 MB.
- Submit while images/videos are still pending. Verify the result
  exists, and its evidence panel shows `awaiting_media`, not complete.
- Leave the Telegram WebView open and confirm remaining files become
  attached. Verify the actual files open from the result page.
- Interrupt connectivity during chunk transfer. Retry and verify server
  receives only missing chunks.
- Close the WebView, open the result's media review link, reselect the
  same source files, and verify resumed uploads. File objects do **not**
  automatically survive Telegram closing.
- Cancel a pending item and ensure `needs_review` appears rather than
  claiming all material was received.
- Check that unauthorized users cannot access the review status or the
  media file endpoints, and that duplicate submission doesn't create
  duplicate results or outbound messages.

Record phone model, Android/iOS, Telegram version, network type, number
of photos/videos, combined size, total upload time, failed/retried items,
and server disk usage/CPU. A passing unit suite is not a substitute for
this test.

## Recovery / rollback

Immediately disable `FOCUS_V2_ENABLED`, then run
`php artisan config:clear` on the staging host to hide all V2 routes.
The classic Focus route remains unchanged. Keep completed evidence and
upload manifests until they are reviewed; **do not run cleanup scripts
against a production DB or delete staging uploads without checking
completed-result references**.

## Known constraints

- Browser upload is at most two concurrent files, chunk size 512 KiB.
  PHP upload cost and mobile throughput remain unbenchmarked.
- Maximum photo 10 MB, video 100 MB; up to 12 attachments per question.
- Retry after closing Telegram requires reselecting the original file;
  permanent offline file retention and audited replacement flows are
  not implemented.
- Completion records which files have registered upload sessions.
  If Telegram is killed before a selected file is registered, that file
  has not yet been durably recorded on the server.
- Config flag and staging artifact preparation are **not** a hosted
  staging deployment.

## Automated transport checks (CI)

`tests/JavaScript/FocusV2Transport.test.mjs` simulates a connection drop and
retry, a 100 MiB video split into 200 requests of 512 KiB, out-of-order
acknowledged chunks and invalid server receipts. GitHub Actions runs these
alongside Vue compilation, Laravel media/submit regression tests and the
staging-preflight gate tests. **These simulations do not test real Telegram
WebView performance, phone storage eviction, network limits or actual
HTTP/proxy configuration.**

Do not substitute a passing CI run for an HTTPS staging host, a separate
test Telegram bot and manual Android/iOS acceptance measurements.
