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
4. On the admin-only **Тестовый сотрудник**, upload real photos and a large
   video, interrupt network access, retry, and verify no real result, statistics
   or Telegram message is created. Check that real controls are unaffected.
5. Attempt submission with a pending upload; it MUST be rejected until each
   file is fully assembled and attached to the draft. After completing the
   transfer, verify server confirmation and one final notification for a
   **real** control only.
6. Verify Classic Focus remains available and an existing saved Classic draft
   can still be opened.
7. On any serious failure, disable with `FOCUS_V2_ENABLED=false`, clear
   configuration and use Classic. Do not delete the new media-upload table.

## Monitoring and limits

Only the authorized admin/supervisor cohort receives the new workflow.
Up to 12 attachments per question, photos <=10 MiB and videos <=100 MiB;
new uploads use 1 MiB chunks (100 rather than 200 requests for a 100 MiB
video); in-progress 512 KiB sessions keep their old chunk grid after upgrade.
Transient network errors trigger up to three bounded automatic attempts.
Telegram WebView may evict local IndexedDB snapshots or file objects, so
after restart some files must be reselected.
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
cache the confirmed **photo or short video (up to 15 MiB)** as a Blob in
the user's scoped IndexedDB, where storage quota permits. The original upload ID is reused when Telegram WebView restarts.
On bootstrap, the server includes each attached media upload ID, so already
saved files are never transferred again. Fully uploaded but unattached server
files are automatically attached without asking for the originals. For pending
uploads, the browser reuses any cached photo/short video and the server's
received-chunk list; if the cache was evicted, it requests the original file. A new browser, another account or a
schema/run mismatch must never silently attach locally stored evidence.

IMPORTANT: Telegram may terminate WebView while a photo is being stored, or
evict its IndexedDB storage. This is a **best-effort** local backup, not a
promise of guaranteed background transfer. The UI explicitly warns when local
backup is unavailable, and backend submission still requires **all** media
to be fully attached. Videos above 15 MiB still need original file reselection
after WebView termination. Video capture now opens a native preview with
**Оставить видео / Переснять** and a fullscreen player. Photo/video status
in the question is independently tracked from answer-draft save. Optional
Imagick HEIC thumbnails are generated only when the host has HEIF support;
otherwise the private-image fallback placeholder remains visible.
The server-backed **Тестовый сотрудник** is a genuine isolated upload
sandbox for admins, not a real employee or a statistical result.

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

## November 24, 2026 performance / phone UX pass

- New media uploads: 1 MiB parts, with persistent backwards compatibility
  for 512 KiB sessions already registered. Each retry uses the existing
  upload UUID and idempotent chunk URL. Transient transport/5xx/429 errors
  retry twice; auth/validation errors do not auto-retry.
- Upload progress is byte-weighted across pending files, visible in a sticky
  status bar. The media selector is a mobile bottom sheet with camera, video
  and multi-file gallery fallback, including WebView safe-area padding.
- Photos and videos up to their respective local backup limits are shown
  immediately while IndexedDB writes proceed. Even a backed-up file is
  **not considered uploaded** until the server confirms its attachment.
- Photos load a small thumbnail in the question grid but use their original
  full-resolution media route in the fullscreen viewer. HEIC falls back to a
  JPEG thumbnail if the browser cannot decode the original.
- Fully transferred `ready` files automatically attach after a safe
  authenticated bootstrap; their upload IDs are never replaced.
- Verify Safari/iOS Telegram and Android Telegram manually with live
  camera, large videos, offline/reopen and device storage quotas. Browser
  simulation cannot guarantee native-camera behavior.

## Messenger-style media and failure recovery — 24 November 2026

The first page keeps its prominent **Комментарий к проверке** and searchable
**Предыдущий сотрудник**. Question-by-question navigation is intentionally
**unchanged**, per supervisor request.

- Choosing multiple photos/videos first opens a confirmation preview. No file
  starts uploading before **Добавить N**; remove any selection before sending.
  A single camera capture still uses its capture-confirm-retake screen.
- All attached/uploading evidence is visible in one fullscreen gallery, with
  next/previous buttons, photo swipes, file status and deletion. Video cards
  lazily request metadata and show the first frame where supported, including
  duration. Real video poster support depends on the browser's decoding and
  HTTP Range implementation; a play placeholder remains safe when unavailable.
- The review screen shows apartment, cleaner, previous cleaner (if supplied),
  dates, general comment, mandatory progress, count of negative scored answers,
  image/video counts and a pending-upload warning. Each finding links back
  to that question in its existing room; question navigation was **not** rebuilt.
- Offline edits queue scoped IndexedDB backup and do not falsely report that
  the server received them. On reconnect the draft is retried at the same
  revision and transport failures are eligible for auto-resume. It is never
  safe to promise local persistence if Telegram evicts storage or the tab
  closes before IndexedDB finishes.
- The test sandbox refuses to switch into real data while a test upload or
  draft-save is still running. After a completed test admins can press
  **Начать новый тест** for another clean, isolated server-backed run.

### Measure actual shared-host performance

New upload chunks remain **1 MiB**, with legacy **512 KiB** sessions safely
resumable. The chunk replay endpoint now hashes stored files as a stream.
The media assembly loop avoids duplicate filesystem metadata lookups and
checks for enough free disk space before assembling a large video.

For a safe synthetic comparison **without production user data or DB changes**:

```bash
php artisan focus-v2:benchmark-media --mb=16 --chunk-kb=512
php artisan focus-v2:benchmark-media --mb=16 --chunk-kb=1024
```

This measures only the host's local disk/PHP assembly; it does not measure
Telegram upload throughput or time to final notification. The command writes
only under `focus-v2-bench/` and removes its temporary files.

For opt-in production measurements, set
`FOCUS_V2_MEDIA_PERFORMANCE_LOGS=true` and run
`php artisan optimize:clear`. Log records contain only byte count, chunk count,
elapsed milliseconds and PHP peak memory; **no apartment names, employee
identity, file names, media content or private paths**. Disable the flag
after collecting a representative sample.

### Device acceptance that browser CI cannot replace

Run real **Android Telegram** and **iOS Telegram** sessions on the deployed
matching PHP/Vite revision, in the isolated admin test sandbox:

1. Fill the first-page comment, choose previous cleaner using search; reload
   and verify both are restored.
2. Take a photo, retake it, then confirm. Add several gallery images/videos,
   remove one before confirmation, swipe the fullscreen gallery and delete a
   server-attached item from the draft.
3. Record an iPhone HEIC image and test preview fallback; try a MOV video and
   a large (>15 MiB) MP4; check actual video playback, duration and cover.
4. Enable airplane mode after an answer and during a chunk upload. Ensure the
   UI never says “Сохранено” before server acknowledgement. Restore network;
   verify draft revision, upload ID and already-received chunks were not
   duplicated.
5. Close/reopen Telegram while a video upload is pending. A short cached clip
   may auto-resume; a large clip must ask for its original file unless fully
   attached on the server. Test full-storage/evicted IndexedDB behavior.
6. Inspect review count, finding-to-question jump, and media-blocked submit;
   finish the test and confirm **no production ControlResponse, notifications,
   statistics or rewards**. Start a new test and verify the blank form.
7. Only after the sandbox passes, submit one intentionally authorized real
   control, verify expected result and Telegram delivery. Keep a DB/uploads
   backup and `FOCUS_V2_ENABLED=false` rollback ready.

Chromium and WebKit Playwright runs validate browser behavior on simulated
mobile viewports, **not native camera permissions, real physical devices,
Telegram WebView lifecycle, or actual shared-host performance**.
