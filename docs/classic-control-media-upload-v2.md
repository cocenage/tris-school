# Classic Control media upload verification

Scope: Classic only, based on `origin/main` at `e619847`. The `e8277d0`
`wire:ignore.self` fix remains in place. No migrations or new dependencies.

## Confirmed defects and fixes

- Autosave could read an old media list, then overwrite the JSON after
  `finishPhotoUpload` had acknowledged a new file. A regression interleaves the
  actual upload acknowledgement with Eloquent's autosave write and reproduced
  zero attachments after `saved`. Autosave now reads and writes under the same
  per-control/per-supervisor lock as media acknowledgement and deletion.
- An upload could be acknowledged between result creation and draft cleanup,
  then deleted without entering the result. Finalization holds that lock through
  copying, commit and cleanup. Existing result/scoring and duplicate rules remain.
- Concurrent/stale delete buttons used shifting array indexes. New attachments
  are deleted by their upload UUID under the lock; legacy index-only attachments
  remain supported. Preview nodes also have stable path-based keys.
- A malformed temporary-file value could return `saved` without a stored file.
  It now returns the existing explicit missing-file error.
- Original photo sizes were rejected before compression. Photos enter preparation,
  then the actual prepared bytes are checked against the effective limit. Videos
  keep their original bytes and are checked before transfer. JPEG encoding is
  used only when smaller; undecodable formats fall back to their original bytes.
- A non-Error rejection could throw inside the queue error handler and strand
  later files. Errors are normalized and each attempt releases its pending slot.
- Local removal could hide an item during server save. Saving and unconfirmed
  items remain visible. After a lost acknowledgement, retry first checks the
  same UUID on the server. An uncertain save blocks finalization until reconciled;
  a definitive rejected optional upload can be removed without losing answers.

The queue remains sequential across all questions. Preparation, upload, server
save, saved, error and cancellation are distinct. Internal navigation is blocked
while media is pending or unconfirmed. Full reload still warns about pending
local files; committed files are restored from the database.

## Measured changes

Five identical valid JPEG fixtures (1,363,240 bytes each), one sequential upload
slot, same local PHP HTTP server, separate equivalent SQLite/storage fixtures
before and after. Times use Node `performance.now()` for real HTTP requests and
local-only `Server-Timing` for application/database time. Each run included one
explicit answer autosave after the five uploads.

| Measurement | Before | After |
| --- | ---: | ---: |
| `_finishUpload` mean response | 149,154 bytes | 4,750 bytes |
| `_finishUpload` median server time | 70.22 ms | 34.59 ms |
| `_finishUpload` median HTTP time | 319.16 ms | 321.34 ms |
| `finishPhotoUpload` median HTTP time | 412.95 ms | 409.98 ms |
| Temporary transfer median HTTP time | 386.03 ms | 393.44 ms |
| All requests, five files + autosave | 8,963.56 ms | 10,023.37 ms |

The intermediate temporary-file acknowledgement is now renderless, reducing its
response by 96.8%. The final media save still renders confirmed previews.
The draft payload/hash are reused instead of rereading media to build them again.
File input events no longer schedule a redundant answer autosave.

**End-to-end acceleration is not established by this sample.** Local request
timings varied, and the total after-run was slower. No production CPU, mobile
encoding, image decoding, rotation, or network-speed claims are made.

## Real HTTP/storage acceptance without a browser

An isolated fixture application exercised the installed Livewire 4 protocol:
`_startUpload` -> signed multipart POST -> `_finishUpload` ->
`finishPhotoUpload` -> reload -> `confirmSubmit` -> authenticated result reads.

- Nine attachments: five JPEG, MP4, QuickTime MOV, WebM and WebP across four
  questions. All nine were read and hashed after reload and after finalization.
- A stale pre-save snapshot retried the same acknowledgement without a duplicate.
- Four Range responses returned HTTP 206 and the correct 32 source bytes.
- Original downloads retained bytes and used attachment disposition.
- One `sent` result, 100% fixture score, zero drafts after finalization;
  nine unique upload UUIDs, nine unique physical paths, all expected sizes,
  SQLite `quick_check=ok`.
- Another supervisor received 404 for the private draft and 403 for the result.

The effective **local HTTP** runtime was `cli-server`: upload 2 MiB, POST 8 MiB,
20 files/request, execution 30 s, input 60 s. Livewire temporary validation was
`required|file|max:12288`; Classic's application cap remains 10 MiB. The client
uses the lower effective transport cap. Production web-runtime limits were not
re-verified and must not be inferred from these local settings.

## Automated coverage and remaining checks

Final focused run: 55 Pest cases, 519 assertions, exit 0 (55 missing-`.env`
bootstrap warnings); 21/21 Node tests. Scoped Pint, PHP syntax, Vite build and
`git diff --check` passed. An oversized multipart request with Livewire's JSON
Accept header returned HTTP 422 without a save acknowledgement. The updated
result page returned HTTP 200 after the final markup changes.

Pest covers interleaved autosave/upload and finalize/upload, real video bytes and
MIME, PNG/WebP, file existence and sizes, reload/finalization, UUID idempotency,
disk cleanup after failed database persistence, 12/13 attachments, stable-ID
deletion, Range and authorization. Node tests cover multiple selections and
additional files while active, cross-question serialization, compression policy,
prepared size checks, cancellation/retry, timeout/late callbacks, unknown save
outcomes, pending counters, non-Error failures and guarded finalization.

The known main-branch test `renders the loader preview only when explicitly
requested` failed before these changes because the current loader is always
rendered. It is excluded from the focused final media run and is not modified.
The missing worktree `.env` generates Dotenv warnings in the isolated Pest
bootstrap; no real environment file is copied or changed.

**BROWSER NOT VERIFIED.** Computer Use was not used for this continuation.
Canvas tests use controlled browser-API doubles, not a rendering engine.
Telegram WebView/Android/iPhone upload, HEIC/EXIF visual orientation, cleaning
detail after JPEG preparation, native file selection, layout and codec playback
still require manual acceptance. MP4/MOV/WebM storage and Range support do not
prove that a particular device can decode its codec.

No production writes, real Telegram sends, historical controls or employee files
were used. The local fixture server and disposable artifacts are separate from
the application's working SQLite and media.
