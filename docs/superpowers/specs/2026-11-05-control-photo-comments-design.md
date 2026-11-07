# Control Photo Comments — Design

## Intent and scope

Make comments attached to control photos survive draft submission and remain readable in short review and completed control results. Reuse the existing response JSON and file metadata. Cover both existing control entry routes so a result does not depend on which form created it.

## Goals

- Store each optional comment on its own media item under `responses[room][question].media[index].comment` in the existing draft and completed response JSON.
- Keep the control-wide `comment` and per-question corrective actions as separate existing fields and show them in their existing context.
- Show a photo comment alongside its photo in pre-submit review and completed results, including when the photo is enlarged.
- Keep empty photo comments absent from the UI; preserve photo/comment ordering and existing media URLs.
- Keep result and media authorization unchanged; completed result screens remain read-only under existing permissions.

## Non-goals and constraints

- No migration, table, model, scoring, penalty, status, or statistics changes.
- No inference or backfill of missing comments. Existing JSON comments, if present, are displayed; absent values remain absent.
- No production database access, data writes, Telegram sends, or deployment.
- Respect existing role checks and private media delivery.

## Approaches considered

1. Render any comment already present in media JSON. This is the smallest display-only fix but does not save comments for new controls because neither entry form currently accepts per-photo comments.
2. Add per-media comment entry to the existing forms, persist it in each media JSON object, and render it beside the photo and in the enlarged-photo view. This reuses current draft and result storage, supports both new and already-populated records, and requires no schema change. **Recommended and selected.**
3. Add a separate table or standalone annotation model. This duplicates the current response/media relationship and requires schema changes without solving a need the JSON structure cannot handle.

## Implementation choices

- Limit per-photo comments to 2,000 characters, matching existing control comment limits.
- Preserve comments through draft restore, media normalization, and final submission; do not accept client-supplied storage paths.
- Render escaped text with line breaks, attached to the same media item. The enlarged view keeps the caption visible.
- Add focused feature coverage for one and multiple comments, empty comments, overall comment, draft/reopen/final result, historical JSON, mobile-friendly layout, and result/media access.

## Open implementation detail

- Verify whether the existing `page-checks.control` review sheet and the alternate Focus review need the same compact comment placement; keep the underlying JSON contract identical.
