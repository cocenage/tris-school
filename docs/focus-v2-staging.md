# Focus Control V2 — isolated staging rollout

## Deployment status

This is a **staging procedure**, not a production deployment. V2 changes
are on branch `codex/focus-control-v2-foundation`; do not merge or deploy
to the production Academy simply to run the media acceptance test.

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
- **V2 feature flag:** `config/focus-v2.php` is off by default in all environments (isolated PHPUnit tests
  explicitly enable it). Enable only in the
  separate staging `.env`:

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
php artisan migrate --force
php artisan config:clear
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

The old Focus route stays available on staging. A production deployment
where `FOCUS_V2_ENABLED` is unset should return 404 for every V2 page
and API endpoint (after normal session/auth middleware).

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
