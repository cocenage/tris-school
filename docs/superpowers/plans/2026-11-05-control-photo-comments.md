# Control Photo Comments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist optional comments on individual control media items and show each comment with its photo in review and completed results.

**Architecture:** Reuse the existing `responses[room][question].media[index]` JSON object in control drafts and final responses. Add safe per-media editing to both existing entry forms, then render the same stored comment next to its media in review, the completed result, and the admin infolist. Keep media access routes and control authorization unchanged.

**Tech Stack:** Laravel 12, Livewire 4/Volt, Blade, Pest 4, Filament 5, Tailwind CSS 4, Alpine.js 3.

**Spec:** `docs/superpowers/specs/2026-11-05-control-photo-comments-design.md`

## Global Constraints

- No migration, new table, model, or separate annotation entity.
- Store a comment only on the matching media object at `responses[room][question].media[index].comment`.
- Limit per-photo comments to 2,000 characters; preserve the existing control-wide `comment` and per-question corrective fields.
- Missing comments stay missing; do not infer or backfill text.
- Do not change scoring, penalties, statuses, or statistics.
- Keep existing role checks, private media delivery, and completed-result read-only behavior.
- Do not access or modify production data, send Telegram messages, deploy, or push.

## Review Focus

- Two media items in one answer can have different comments and keep their comments after draft restore, deletion/reordering, and submission.
- Old result JSON without a `comment` key renders without placeholder or invented text.
- An unauthorized staff member cannot load result comments or the result media endpoint.
- The zoomed image remains usable on a narrow viewport and keeps its associated comment visible.
- Existing overall comments and corrective actions remain separate and visible in their existing contexts.

---

### Task 1: Capture and persist comments per media item

**Files:**
- Modify: `resources/views/components/forms/⚡page-control.blade.php`
- Modify: `resources/views/components/forms/⚡page-control-focus.blade.php`
- Test: `tests/Feature/ControlWorkflowTest.php`

**Interfaces:**
- Persist each trimmed optional comment as `comment` on its existing media array entry.
- In both Livewire forms, use `saveMediaComment(int $roomIndex, int $questionIndex, int $mediaIndex, string $comment): void` to update only the comment on a server-known media entry, enforce the 2,000-character limit, and save through the existing draft path. Never accept a client-provided disk or path through this action.
- Existing media and overall comment fields remain in their current JSON/database locations.

- [ ] **Step 1: Write failing tests**
  - Add `it('persists distinct Classic media comments across draft restore and final submission')`; upload two media files for one answer, set different comments, restore the draft, submit, and assert each saved media entry retains its own comment and order.
  - Add `it('persists Focus media comments without unlocking saved media metadata')`; set distinct comments through the form action, restore the draft, submit, and assert media paths remain server-owned while comments survive.
  - Add `it('keeps empty photo comments absent and preserves the overall control comment')`; submit one commented and one blank media item with a control-wide comment and assert only the first media entry has non-empty comment text.
- [ ] **Step 2: Run those tests and confirm they fail for missing per-media comment support**

  Run: `php artisan test --compact tests/Feature/ControlWorkflowTest.php --filter="media comments"`

  Expected: the new assertions fail because no per-media comment can currently be saved/restored.
- [ ] **Step 3: Implement the per-media input and save action in both existing forms**
  - Render a small optional text input for each saved image/video, bound to its room/question/media indices.
  - Update only that media entry’s `comment` and persist using the existing draft autosave; preserve comments when normalizing answers and copying media into the completed response.
  - Do not modify scoring or acceptance rules.
- [ ] **Step 4: Run the focused tests and confirm they pass**

  Run: `php artisan test --compact tests/Feature/ControlWorkflowTest.php --filter="media comments"`

  Expected: all added media-comment persistence tests pass.

### Task 2: Render stored comments in review and completed results

**Files:**
- Modify: `resources/views/components/forms/⚡page-control.blade.php`
- Modify: `resources/views/components/forms/⚡page-control-focus.blade.php`
- Modify: `resources/views/components/profile/⚡page-check-result.blade.php`
- Modify: `app/Filament/Resources/ControlResponses/Schemas/ControlResponseInfolist.php`
- Test: `tests/Feature/ControlWorkflowTest.php`
- Test: `tests/Feature/ControlInterfaceTest.php`

**Interfaces:**
- Read the existing media `comment` value without changing `ControlResponse::mediaItems()`’s file ordering or URL contract.
- Render comments as escaped plain text with preserved line breaks, next to the matching media item.
- The profile result photo zoom uses a dismissible, keyboard-accessible modal with the same photo and comment; it does not change result authorization.

- [ ] **Step 1: Write failing result and access tests**
  - Add `it('shows each photo comment beside the matching photo and in its zoom view')`; a historical fixture with two different comments renders each beside the correct photo in a finished result.
  - Add `it('does not show a placeholder for historical media without a comment')`; a result with one legacy media object lacking `comment` shows no invented caption.
  - Add `it('keeps the overall comment and corrective actions separate from photo comments')`; assert each existing remark remains labeled and attached to its current context.
  - Add `it('preserves result and media access rules for photo comments')`; the associated cleaner, supervisor, and admin can read the result, while an unrelated user is denied both the result and private media endpoint.
  - Add a mobile-focused assertion for responsive thumbnail/modal classes and non-clipped comment text.
- [ ] **Step 2: Run the new tests and confirm they fail because comments are not rendered**

  Run: `php artisan test --compact tests/Feature/ControlWorkflowTest.php tests/Feature/ControlInterfaceTest.php --filter="photo comment"`

  Expected: result rendering and mobile/access assertions fail while existing access behavior remains unchanged.
- [ ] **Step 3: Render comments next to photos in the Classic review, Focus review, completed profile result, and admin infolist**
  - Show non-empty text only; preserve line breaks and HTML escaping.
  - In the profile result, replace the bare image tab link with a mobile-friendly accessible zoom view that keeps the photo comment visible.
  - Keep the overall comment and corrective-action display unchanged.
- [ ] **Step 4: Run the focused result/access tests and confirm they pass**

  Run: `php artisan test --compact tests/Feature/ControlWorkflowTest.php tests/Feature/ControlInterfaceTest.php --filter="photo comment"`

  Expected: the matching photo captions, legacy-empty case, responsive markup, and authorization assertions pass.

### Task 3: Verify the scoped change

**Files:**
- Verify the files listed in Tasks 1–2; do not add unrelated source changes.

- [ ] **Step 1: Run the full control workflow and interface tests**

  Run: `php artisan test --compact tests/Feature/ControlWorkflowTest.php tests/Feature/ControlInterfaceTest.php`

  Expected: all control regression tests pass in isolated in-memory SQLite.
- [ ] **Step 2: Run PHP lint and formatting checks on modified PHP files**

  Run `php -l` for each modified PHP/Volt file and `vendor/bin/pint --test` for modified PHP files.

  Expected: no syntax errors and Pint passes.
- [ ] **Step 3: Compile the affected Blade views and perform a mobile browser smoke check when browser access is available**

  Expected: all affected views compile; at a narrow viewport, the photo zoom opens, closes, and keeps its caption readable without horizontal clipping.
- [ ] **Step 4: Run `git diff --check` and inspect the final diff**

  Expected: no whitespace errors; only photo-comment behavior, focused tests, and this plan/design documentation changed.
