# Focus Control V2 — technical audit and migration plan

## Automated verification (2026-11-23)

GitHub Actions run 45113995739 completed successfully on this experimental branch:
https://github.com/cocenage/tris-school/actions/runs/45113995739

The workflow successfully ran composer install, npm ci, npm run build and
php artisan test tests/Feature/ControlWorkflowTest.php against the isolated
in-memory SQLite test configuration. This verifies the build and the
existing feature regression suite. **It does not verify** real Telegram
WebView upload performance, large photo batches, real 100 MiB videos,
weak-network recovery, or staging/production migrations.


## Status (2026-11-23)
Foundation + revisioned draft API + Vue 3 preview + independent chunked media + transactional final submission. Original Livewire Focus is untouched.
V2 endpoints (authenticated admin/supervisor only):
- `GET /checks/control/focus-v2` — Vue preview.
- `GET /checks/control/focus-v2/bootstrap` — schema and owner draft.
- `POST /checks/control/focus-v2/draft` — revisioned save and safe retry.
- `POST /checks/control/focus-v2/submit` — final submission from the persisted draft.
- `POST /checks/control/focus-v2/media` — initialize or resume a file.
- `PUT /checks/control/focus-v2/media/{upload}/chunks/{index}` — chunk transfer.
- `GET /checks/control/focus-v2/media/{upload}` — received chunk status.
- `POST /checks/control/focus-v2/media/{upload}/complete` — assemble/MIME-check.
- `POST /checks/control/focus-v2/media/{upload}/attach` — idempotently attach.
- `DELETE /checks/control/focus-v2/media/{upload}` — cancel unattached upload.
Vue supports setup, questions, room navigation, progress, background save, media upload and final submission. The old Focus is still available as a fallback. The V2 preview is deliberately not linked in Academy navigation.

## Findings in current implementation

1. `resources/views/components/forms/⚡page-control-focus.blade.php` combines PHP persistence methods, authorization, navigation, form rendering, Alpine state and file upload state (about 99 KB / 1,852 lines).
2. Answers use local Alpine `@entangle` and an autosave debounce (900 ms), then `$wire.saveDraft()`. Local state is transient and is not a durable offline record.
3. Media pipeline is `beginMediaUpload` -> Livewire `$wire.upload` -> `attachMedia` -> `saveDraft`. Uploads are chained by `this.queue = this.queue.then(...)` and therefore serialized. The Livewire signed-upload override still routes the whole binary through PHP temporary upload configuration; PHP `upload_max_filesize` and `post_max_size` remain relevant.
4. `attachMedia` writes to the local disk and invokes `saveDraft` (including canonical schema checking, answer validation and revision verification) for every attachment. The current implementation has upload UUID-based duplicate-attach protection, which must not be regressed.
5. Final submission is protected by `ControlRunManager::submitOnce` (unique `submission_token`, draft revision), and `ControlResponse::calculateScores` is the authoritative score engine. These should be reused as-is in V2.
6. Existing image and video limits: 10 MiB per image, 100 MiB per video, at most 12 media items per question. Supported MIME types are defined in `FocusMediaLimits`.

These are structural risks established from source inspection. Actual bottleneck proportions still require measurements on a representative mobile device and production-like network.

## Target design

- **Vue 3 + Vite, separate Focus entry**: UI state, room/question navigation, pending changes and visual feedback. Do not retrofit the entire Academy.
- **Laravel authenticated JSON endpoints**: schema/bootstrap, revisioned draft saving, finalize. Reuse `ControlSchema`, `ControlRunManager`, `ControlResponse::calculateScores`, notification service and existing Telegram authorization/session.
- **Local recovery**: write changed answers to an IndexedDB draft keyed by authenticated user, control, run ID and schema version. Keep a per-run mutation queue and show distinct `local`, `syncing`, `synced`, `error`, `conflict` indicators. IndexedDB in a Telegram WebView can be evicted; the backend remains the source of truth and offline retention is best-effort, never guaranteed.
- **Independent file transfer**: do not put binary uploads into serialized component state. Give each media file a stable `upload_id`, status, verified server receipt and safe retry. Prefer direct-to-object-storage multipart/resumable upload when server deployment and storage support it; otherwise build a dedicated HTTP upload pipeline with explicit body limits and retries. Do not assume current PHP hosting accepts a 100 MiB multipart request.
- **Server-side ownership and integrity**: attach only verified uploads owned by the active supervisor/run, ensure same control/schema/question, enforce MIME/size/count on the server, and keep an idempotent link between `upload_id` and attached media.
- **Submission safety**: reject finalization while relevant uploads or answer mutations are unresolved; never mark a submission complete before the server returns the durable `ControlResponse`.

## Sequence

1. **Bootstrap API and contract tests (this commit series)**. No migration, no visual change, no production behavior change for existing Focus.
2. **Revisioned draft API** using `ControlRunManager`, with tests for concurrent edits, schema conflict, auth and idempotency.
3. **Vue question flow** under a separate, guarded URL. Implement responsive setup, rooms, review and client-side form state; preserve current scoring behavior.
4. **Independent media API**: bounded concurrency, per-file progress, cancel/retry and attachment to exact question. Test partial network failure, duplicate requests, expired authorization, file count and 100 MiB transfer.
5. **Resilience**: reload, background/foreground transitions, stale tabs, Telegram WebView lifecycle, offline/online sync and new schema conflict UX.
6. **Compare and rollout**: keep old Focus route available, run side-by-side on real devices, then gradually opt in; revert by restoring the old route.

## Acceptance gates

- 100% current scoring parity for known fixtures.
- No lost acknowledged answer after refresh; no false `saved` state when server confirmation is absent.
- No double submissions or duplicate media attachments on retry.
- Unrelated questions remain usable during file transfer.
- Real-life large videos can retry safely after a dropped connection; browser/host-specific limits clearly surfaced.
- Mobile interaction latency measured separately from server save/upload latency, with a test report.

## Current endpoint contract

`GET /checks/control/focus-v2/bootstrap` provides:
`control`, `rooms`, `question_ids`, `run`, `setup`, `answers`, `media`, `people`, and `apartments`.
- Requires active authenticated admin or supervisor.
- Returns existing active draft only for its owner and matching schema version.
- On schema conflict: `run.schema_conflict = true`; no old answers are silently ported.
- Media objects contain authorized presentation URLs, not local filesystem paths.
- Response is `Cache-Control: private, no-store`.
- The API does **not** yet persist or submit anything.


## Phase 2: revisioned draft API contract (implemented, not yet deployed)

\`POST /checks/control/focus-v2/draft\`: same-site authenticated JSON request (normal Laravel session + CSRF protection).
Request body:

\`\`\`json
{
  "control_id": 1,
  "run_id": "00000000-0000-4000-8000-000000000001",
  "schema_version": "<64 lowercase hex chars from bootstrap>",
  "draft_id": null,
  "expected_revision": 0,
  "setup": {
    "cleaner_id": 123,
    "apartment_id": 456,
    "is_assigned": false,
    "previous_cleaner": "",
    "cleaning_date": "2026-11-23",
    "inspection_date": "2026-11-23",
    "comment": ""
  },
  "answers": [[{
    "selected": "yes",
    "custom": "",
    "corrective": {"repeats": null, "action": "", "recheck": null}
  }]]
}
\`\`\`

The response shape is \`{run: {id, draft_id, revision, schema_version}, saved_at, replayed}\`.
The next edit sends the returned \`draft_id\` and \`expected_revision\` set to the returned revision.
When the client loses an acknowledgement it retries the **same snapshot with the same expected revision**. If that snapshot is already persisted as the immediately next revision, the API returns it with \`replayed: true\`; otherwise it returns a conflict.

- \`409\` JSON \`error\`: \`schema_changed\`, \`active_draft_exists\`, \`draft_not_found\`, \`run_mismatch\`, \`already_submitted\`, \`revision_conflict\`. On \`revision_conflict\`, reload bootstrap and require an explicit merge decision. Never silently replace a new server revision.
- \`422\` Laravel validation JSON: malformed answer, invalid setup, invalid room/question structure or attempted \`media\` mutation.
- All answers are validated with \`ControlSchema\`; question IDs are attached on the server. The browser cannot edit media paths or arbitrary schema data.
- The server preserves existing draft \`media\` for each question independently of answer edits.
- Existing scoring and \`ControlRunManager\` are reused. No database migration is required for this phase.
- Like bootstrap, successful and conflict responses use \`Cache-Control: private, no-store\`.
- This API does **not** yet provide a separate media upload pipeline, completed result, durable client-side offline queue or Vue form. Do not present Phase 2 as a finished Focus V2.

Validation to run on an **isolated test database**:

\`\`\`bash
php artisan test tests/Feature/ControlWorkflowTest.php
\`\`\`

Tests were added in this branch but not executed by GitHub Actions at the time of this document update. Before deploying the endpoint, test it on a staging/isolated environment and compare output with the original Livewire Focus.


## Phase 3: isolated Vue preview (experimental)

- Build entry: `resources/js/focus-v2/main.js` and `resources/js/focus-v2/FocusV2App.vue`.
- Preview URL: `/checks/control/focus-v2` with the existing Laravel session and CSRF token.
- Vite loads the Vue entry only on the preview page; other Livewire and Alpine screens remain unchanged.
- The app displays setup, questions, room progress, optional text and corrective fields for negative scored answers.
- Autosave uses the revisioned draft endpoint. On an uncertain response it retries the exact same body before saving subsequent changes.
- On 409 conflict, automatic writes stop. Offline restoration, upload resumability and V2 submission remain future phases.
- Question images are exposed as display URLs by bootstrap without changing the canonical schema hash.

### Install and verify

On a network-connected local or staging environment, run:

```bash
npm install
npm run build
php artisan test tests/Feature/ControlWorkflowTest.php
```

The new lockfile entries were reconstructed from official published package manifests because npm registry access was unavailable here. `npm install` must regenerate the package integrity hashes; COMMIT the regenerated package-lock.json before release. Then run `npm ci && npm run build` as a clean-install check. PHP tests must use an isolated test database (APP_ENV=testing); never run against production data.

### Preview acceptance

1. Authenticate as active admin/supervisor and visit `/checks/control/focus-v2`.
2. Verify staff, apartments, questions and optional question images.
3. Save answers; refresh after `Сохранено`, confirm restoration.
4. Retry a dropped response and check that draft revision was not duplicated.
5. Simulate concurrent tabs and confirm conflict detection.
6. Use `Сохранить и завершить в старом Focus`; verify the old page restores answers and can complete the check.
7. Measure speed and verify no new Vue payload on unrelated pages.

**Not yet verified:** full Vue build, Laravel/Pest suite, host upload limits, and real 100 MiB video inside Telegram. The V2 screen remains a preview.


## Phase 4: Independent chunked media (implemented in branch, not deployed)

Vue now uploads up to two files concurrently, with per-file progress, cancel and retry. Each binary HTTP PUT contains at most 512 KiB; no Livewire upload state or one-shot 100 MiB multipart POST.

Upload sessions are stored in `focus_v2_media_uploads`. UUID, supervisor, draft, run, schema, question, MIME, size and status are verified server-side. Complete reassembles parts and validates file MIME with PHP fileinfo. Attach uses optimistic draft revision and ensures no more than 12 files per question; repeated attach never duplicates a file.

Retrying initialization returns received chunk indexes; retrying an identical chunk succeeds. Conflicting content or unauthorized access is rejected. Chunk cleanup follows database commit. The `focus-v2:prune-media` command runs daily at 03:25 Europe/Rome. It removes pending, ready and cancelled sessions older than 48 hours; when a completed run has deleted its draft, it also removes old attached upload *metadata* without deleting the evidence file. Staging rows intentionally have no cascading draft foreign key, so abandoned chunks remain discoverable for cleanup.

**Limitations:** File objects do not survive closing Telegram or reloading the page; current resume requires keeping the page session. Whole-file checksum is not yet implemented. HEIC/MOV support depends on PHP `fileinfo` magic data. Removing already attached media still requires the old Focus.

### Required checks before rollout

On a non-production system using an isolated test database:

```bash
npm install
npm ci
npm run build
php artisan test tests/Feature/ControlWorkflowTest.php
```

The lockfile was reconstructed from official package manifests because npm registry access was unavailable in the coding environment. Regenerate it using npm, commit the resulting lockfile, then run `npm ci` before any release. Migrate the new table only after staging tests pass.

Manual Telegram tests: a small image, 100 MiB MOV/MP4, interrupted transfer retry, two parallel files, duplicate complete/attach, concurrent stale drafts, cancellation, and old Focus handoff.

**No successful Laravel/Pest test run, complete Vite build or live Telegram upload has been observed in this environment.**
## Phase 5: Vue final submission (implemented in branch, not deployed)

- Route: POST /checks/control/focus-v2/submit (authenticated active admin/supervisor, CSRF, preview write guard).
- Request includes only control_id, run_id, schema_version, draft_id and expected_revision. No client-controlled responses, scores, file paths or notification settings.
- The endpoint locks the run using the same cache key as the existing Livewire finalizer and locks its draft row within a database transaction.
- Before finalization it validates the canonical schema, draft owner/revision, setup, required answers and already attached evidence. Registered pending/ready upload sessions are now permitted at sign-off. Legacy drafts without question IDs may pass if their schema snapshot matches; canonical IDs are attached before submission.
- Scoring uses the existing ControlResponse::calculateScores() rules. ControlRunManager::submitOnce() protects the unique submission_token and removes the active draft only after writing the result.
- The same notification outbox is used via ControlCompletionNotificationService::queueFor(), and the control_completed activity log entry is created inside the transaction.
- A repeated POST with the same run ID after a committed result returns the original result (replayed=true) and does not create another notification.
- The Vue review screen waits for acknowledged answer saves, registers selected files in a durable server manifest, and allows completion while transfers are pending. It displays saved score and evidence readiness separately. A lost response can be retried with the unchanged run ID.
- Old Focus remains accessible while V2 is tested; there is no navigation replacement or automatic rollout.

### Required release checks

On an isolated test database and test server:

    npm install
    npm ci
    npm run build
    php artisan test tests/Feature/ControlWorkflowTest.php

Before any production release, regenerate the reconstructed package-lock with real npm registry integrity metadata and commit it. Validate end-to-end inside Telegram WebView: saved answers, video transfer and retry, pending-media submission block, final score parity, link permissions, duplicate submission after response timeout, and Telegram notification dispatch.

No full Laravel/Pest suite, Vite build or real mobile Telegram test has been run within this GitHub-only editing session.


## Phase 6: deferred photo AND video evidence (experimental, not deployed)

After completion, the registered evidence manifest survives deletion of the draft. Upload sessions reference the final ControlResponse via control_response_id. No new uploads may be silently registered after sign-off; only previously registered upload UUIDs can resume.

Evidence readiness is calculated from server-side records:
- awaiting_media: at least one transfer is pending or assembled but not yet attached.
- needs_review: no transfer is pending, but at least one selected upload was cancelled or never delivered.
- complete: all registered transfers are attached, with no cancelled transfers.

The canonical scoring, answers and completion time are independent of evidence readiness. Final Telegram completion notifications are deferred until evidence state is complete. Files attached later update only the authorized result's evidence list; scored fields remain unchanged.

Private post-submit status endpoint: GET /checks/control/focus-v2/results/{controlResponse}/media.
Resume/review page: /checks/control/focus-v2/review/{controlResponse}. The existing profile result shows an evidence status link to this page for authorized supervisors/admins.

The browser uploads up to two files at a time. Before sign-off, it creates an idempotent server reservation for every selected photo/video still waiting in the client. If these reservations cannot be confirmed due to network errors, final sign-off is blocked rather than silently dropping evidence.

Closing Telegram stops live HTTP transfers. After reopening the review page, users can reselect single or multiple matching original files; received server chunks are reused. A fully assembled file can be attached without reselecting its binary. Browser file objects are not guaranteed to survive WebView termination.

Cancelled media remains visibly flagged as needs_review and cannot be quietly swapped with a different file. Audited replacement/review workflow, object storage, capacity planning, whole-file checksums, and long-term retention remain future tasks. The pruning command skips completed-result manifests, preventing silent deletion but requiring an explicit storage retention policy.

Do not roll out before npm lockfile regeneration, npm ci, npm run build, Laravel tests on a dedicated test database, and real Telegram WebView testing (large photo batches, 100MB video, retry after interruption, logout/reopen, delayed notifications, and multi-device race behavior). None of these full integration checks have been observed passing in this GitHub editing environment.

## Phase 7: best-effort recovery of unsynced Vue answers (experimental)

- The Vue client now writes **only form setup and answer values** to browser IndexedDB on every edit. File blobs, media paths, people lists and auth data are not stored. The cache is scoped to the authenticated user ID, active control ID and canonical schema version.
- On boot, the server is read first; the browser then checks the local copy. If the server revision and draft identity still match (or the run was never saved and there is no server draft), it **offers** restoration. It never silently replaces server answers.
- If the server changed, recovery is blocked. The local JSON is available for manual comparison/copy until the user explicitly selects the server copy. Browser session storage remains a *best effort*, not a durable guarantee; Telegram WebView/OS may evict IndexedDB.
- Every acknowledged draft save clears the local copy when no newer edits remain. If the user continues typing during the request, the recovery record is updated with the new revision instead. Writes and deletes are serialized.
- A successful final submission also queues local cleanup. If a user closes Telegram during final submission, the server is still authoritative and its run/revision checks prevent silent duplicate edits; stale local data may require manual dismissal.
- Error/status UI tells the supervisor when IndexedDB is unavailable. This change does not store binary uploads for resume: for pending files after WebView shutdown, the user still needs to reselect the original file.

Validation: the branch's Focus V2 workflow includes Node unit tests covering user/schema scope, identical snapshots, revision conflicts, expiry and local write ordering, plus the existing Vue build and Laravel feature suite. Device-level testing remains required before rollout.

