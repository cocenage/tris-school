# Focus Control V2 — technical audit and migration plan

## Status (2026-11-23)
Foundation + revisioned draft API + Vue 3 preview + independent chunked media. Original Livewire Focus is untouched.
V2 endpoints (authenticated admin/supervisor only):
- `GET /checks/control/focus-v2` — Vue preview.
- `GET /checks/control/focus-v2/bootstrap` — schema and owner draft.
- `POST /checks/control/focus-v2/draft` — revisioned save and safe retry.
- `POST /checks/control/focus-v2/media` — initialize or resume a file.
- `PUT /checks/control/focus-v2/media/{upload}/chunks/{index}` — chunk transfer.
- `GET /checks/control/focus-v2/media/{upload}` — received chunk status.
- `POST /checks/control/focus-v2/media/{upload}/complete` — assemble/MIME-check.
- `POST /checks/control/focus-v2/media/{upload}/attach` — idempotently attach.
- `DELETE /checks/control/focus-v2/media/{upload}` — cancel unattached upload.
Vue supports setup, questions, room navigation, progress, background save and media upload. **V2 final submission is NOT implemented.** Save and finish in old Focus. The preview is not linked in Academy navigation.

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

**Limitations:** File objects do not survive closing Telegram or reloading the page; current resume requires keeping the page session. Whole-file checksum is not yet implemented. HEIC/MOV support depends on PHP `fileinfo` magic data. Removing already attached media and final submission must still be handled by old Focus.

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