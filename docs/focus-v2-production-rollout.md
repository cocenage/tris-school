# Focus Control V2 — production release

## Intended audience and routing

The `/checks` card **Фокус контроль** links to the Vue 3 Focus client for
**active admin and supervisor accounts only**. The old Livewire Focus remains
available at `/checks/control/focus` and is also linked from the Vue client.

Vue 3 URL: `/checks/control/focus-v2`. Only authenticated eligible staff may
use its endpoints; this rollout does not grant cleaners control privileges.

The default release switch is `FOCUS_V2_ENABLED=true` (also when unset).
**Rollback:** set `FOCUS_V2_ENABLED=false`, then run
`php artisan optimize:clear`. The `/checks` card automatically falls back to
Classic Focus, and the Vue routes return 404. Existing server-side drafts,
submitted controls and media are **not deleted** by this switch.

## Mandatory precautions

- Take a full database and uploads backup before migration; confirm available
  storage for large media (assembly can temporarily require about 2x the file).
- Deploy PHP code and **Vite assets from the same GitHub commit**. Never use a
  Vue build from another revision. PHP 8.4 should be used on the Academy host.
- Do not run `npm ci` or `npm run build` on shared hosting. GitHub Actions
  builds the assets on push to `main`: use the `focus-v2-vite-build` artifact
  from the **successful** run for the deployed commit.
- Check the server's existing `FOCUS_V2_ENABLED` variable. An existing
  `false` value overrides the new default and keeps Vue disabled.
- Perform `php artisan migrate:status` and `php artisan migrate --pretend`
  before `migrate --force`. Migrations add
  `focus_v2_media_uploads` and its result reference. Never point to a
  different database just to make the migration pass.
- Confirm that the previous assets have been backed up before replacing
  `public/build`. Keep old hashed files until all clients have refreshed;
  publish the **new manifest last** to avoid referencing missing assets.
- Check staging first when possible. Automated unit/feature/transport simulation
  is not proof of real Telegram WebView performance.

## Server commands (run from the Laravel app root)

```bash
git status --short
git fetch origin
git pull --ff-only origin main

PHP_BIN="${PHP84:-php}"
"$PHP_BIN" artisan migrate:status
"$PHP_BIN" artisan migrate --pretend
```

After backup and verification, upload the matching GitHub Actions build
artifact to a temporary directory (for example `/tmp/focus-v2-build`)
containing `manifest.json` and `assets/`.

Example after inspecting the archive layout and validating the paths:

```bash
test -f /tmp/focus-v2-build/manifest.json || exit 1
test -d /tmp/focus-v2-build/assets || exit 1
mkdir -p public/build/assets
cp -a /tmp/focus-v2-build/assets/. public/build/assets/
cp -a /tmp/focus-v2-build/manifest.json public/build/manifest.json

PHP_BIN="${PHP84:-php}"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
```

## Acceptance checklist

1. Log in as an active admin and supervisor. Confirm `/checks` card opens
   `/checks/control/focus-v2`; verify a cleaner cannot open it.
2. Confirm `/checks/control/focus-v2/bootstrap` returns data for authorized
   accounts; invalid/unauthed requests remain protected.
3. Choose staff/apartment, fill questions, refresh, confirm drafts restore.
4. Upload photos and one large video on the test account, interrupt the network,
   retry and confirm all media reach the correct result.
5. Submit with unfinished registered uploads and check `awaiting_media`,
   evidence recovery, notification delay and eventual `complete`.
6. Verify Classic Focus remains available and an existing saved Classic draft
   can still be opened.
7. On any serious failure, disable with `FOCUS_V2_ENABLED=false`, clear
   configuration and use Classic. Do not delete the new media-upload table.

## Monitoring and limits

Only the authorized admin/supervisor cohort receives the new workflow.
Up to 12 attachments per question, photos <=10 MiB and videos <=100 MiB;
uploads use 512 KiB chunks. Telegram WebView may evict local IndexedDB
snapshots or file objects, so after restart some files must be reselected.
CI is necessary but not sufficient for safe mobile acceptance.

The experimental pruning command remains disabled on production for now;
monitor free storage manually and implement an audited retention policy
before enabling automatic cleanup of completed media.

## Follow-up: November 2026 supervisor UX and safe testing

- Staff and apartment pickers include search and image thumbnails. The inspection
  comment is visible on the first page. The corrective-question section is
  temporarily hidden on negative answers.
- The per-question media menu offers **take photo**, **record video**, and
  **choose files**, with a thumbnail/video gallery and transfer progress.
  Mobile camera capture is a browser/OS capability, not an app permission
  guarantee.
- Every registered photo/video must be attached to the server-side draft
  before final submission. The backend rejects incomplete uploads regardless
  of browser state. After closing Telegram mid-transfer, the next bootstrap
  lists outstanding uploads and asks the supervisor to select matching originals;
  previously received chunks are reused.
- The built-in **Тестовый сотрудник** option appears only to active
  administrators. From this release, it is a **server-backed isolated sandbox**.
  The test draft uses a distinct `state=sandbox` record (production uses
  `state=active`) and the upload pipeline stores files only under
  `focus-v2-sandbox/{supervisor}/{run}/`. The sandbox can test real chunked
  photo/video transfers, retry and attach, and can compute a preview score.
  Finishing it changes state to `sandbox_complete` and **does not create**
  any ControlResponse, notification, activity log, statistics, reward event,
  or Telegram message. A regular supervisor cannot supply `test_mode=true`
  to access it. The real draft is never overwritten when entering or exiting
  the sandbox.
- Test files and test drafts are deleted after seven days by the scheduled
  `focus-v2:prune-sandbox` command, including production deployments.
  Old Classic/real control media and results are never touched by the
  sandbox pruner.
- An unfinished control cannot be sent when uploads are pending, even in a
  second tab via direct POST. Explicitly cancelled uploads are omitted from
  the final manifest. This prevents falsely completed evidence.
- Stage and device testing still required: iOS/Android camera capture,
  retries, large video, interrupted transfers, and authenticated thumbnails.

## Photo-first acceptance: November 24, 2026

The primary media action for a question opens the phone camera or library.
After taking a photo, the supervisor sees a **large preview** and explicitly
chooses **Оставить фото** or **Переснять**. Confirmed photos appear in the
same question gallery immediately. Tapping a photo opens full-screen preview;
uploaded draft photos can be removed by their owner before submission, using
a revision-checked server endpoint. File names and chunk details are no longer
the focus of the question UI.

**Before transfer**, the client creates a draft on the server and attempts to
cache the confirmed **photo** (not video) as a Blob in the user's scoped
IndexedDB. The original upload ID is reused when Telegram WebView restarts.
On bootstrap, the server includes each attached photo's upload ID, so already
saved photos are never transferred again. For pending uploads, the browser
reuses any cached photo and the server's received-chunk list; if the cache was
evicted, it requests the original image. A new browser, another account or a
schema/run mismatch must never silently attach locally stored evidence.

IMPORTANT: Telegram may terminate WebView while a photo is being stored, or
evict its IndexedDB storage. This is a **best-effort** local backup, not a
promise of guaranteed background transfer. The UI explicitly warns when local
backup is unavailable, and backend submission still requires **all** media
to be fully attached. Videos remain file-reselection based if Telegram WebView is terminated
before completing the upload. Video capture now opens a native preview with
**Оставить видео / Переснять** and a fullscreen player. Photo/video status
in the question is independently tracked from answer-draft save. Optional
Imagick HEIC thumbnails are generated only when the host has HEIF support;
otherwise the private-image fallback placeholder remains visible.
The existing browser-only demo employee is **not** a backend test account.

Acceptance on real Telegram devices still required:
1. Open a question, take a photo, retake it, then confirm one image.
2. Confirm the photo appears immediately and can open full-screen. Move to a
   different room while it uploads. Verify the server-backed saved state.
3. Close and reopen Telegram mid-upload; check whether photo recovers from the
   on-device Blob or prompts to reselect it, without duplicating evidence.
4. Delete an already attached photo; verify it disappears after reopening and
   cannot be accessed by a different supervisor.
5. Deny browser storage / exhaust quota: the UI must warn and keep submission
   blocked until upload is confirmed by the server.

## Server sandbox security checklist

- Confirm test mode is available only to active admin accounts and is denied
  to supervisors, cleaners, and role-preview impersonation sessions.
- Test draft save requires the reserved cleaner identifier `__test__` but
  persists `cleaner_id = NULL` and `state = sandbox`. A normal save
  cannot edit it. A normal submit cannot finalize it.
- Real media upload endpoints accept `test_mode = true` only for an admin
  with a matching sandbox draft ID/run ID. File storage paths are segregated
  and all preview routes check both the account and sandbox draft state.
- Test submit validates the same answers and attached media before returning
  scored feedback, but never writes into `control_responses`, notifications,
  activity logs or reward tables. An acknowledged retry is idempotent.
- Run `php artisan focus-v2:prune-sandbox --days=7` for explicit cleanup.
  Confirm the task scheduler runs daily at 03:40 Europe/Rome and only removes
  `sandbox`/`sandbox_complete` drafts and their sandbox-prefixed storage.
- Run real Telegram Android and iOS device tests before rollout. Browser
  Chromium simulation cannot prove native camera capture or WebView quota
  behavior. When HEIC conversion libraries are absent, use the fallback.
