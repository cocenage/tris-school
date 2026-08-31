
### `.ai/DECISIONS.md`

Сюда кладём только решения, которые уже приняли и не хотим переизобретать.

```md
# Architecture and Product Decisions

This document records decisions that should not be casually reversed.

## Databases

Telegram analytics uses the separate Laravel connection:

`analytics`

Do not assume Telegram analytics tables belong to the primary database.

## Telegram callbacks

Telegram identity does not bypass application authorization.

Callback actions must still resolve a valid application user and preserve role/access checks.

Admin/supervisor authorization must not be removed merely to make callback buttons work.

## Telegram Rich Messages

Rich Telegram messages are optional.

The application keeps the legacy HTML Telegram transport.

Rich transport is controlled by a feature flag and must not be treated as guaranteed production transport.

Network failure after Telegram receives a request is sensitive to duplicate delivery.

Do not casually add fallback retries that may send the same notification twice.

## Telegram private messages

The work webhook is for work context.

Private assistant behavior is a separate feature and should not be mixed into work webhook logic without an explicit task.

## Apartment knowledge base

Apartment access uses explicit access records together with server-side authorization.

Do not replace this with frontend-only visibility.

Apartment/user files may use private local storage.

Do not assume uploaded apartment files are public URLs.

## Apartment Telegram import

Telegram import exists.

The intended product flow is:

Telegram export
→ preview/import
→ draft apartment knowledge
→ manual cleanup/organization
→ publication

Import is not automatically trusted final content.

AI enrichment is not currently part of the application runtime.

## Control quality

Existing Control / ControlResponse scoring and business rules are considered domain behavior.

UI/UX work must not silently change scoring or result semantics.

## Mobility

Historical/raw mobility records may remain in the database.

User-facing digests should filter based on relevance/current state instead of destructively deleting historical source data.

Real production-like rows take priority over synthetic fixtures when diagnosing incorrect digests.

If a wrong alert appears, inspect the exact database row and the exact condition that allowed it through.

## Operational context

Operational preview/context builders are intended to be read-only context generation.

Do not convert preview commands into external-write flows without an explicit requirement.

## Scheduler

Current scheduler responsibilities include:

- calendar notifications;
- task deadline checks;
- mobility synchronization;
- mobility digest;
- TRIS Mare synchronization.

Do not alter scheduling while working on unrelated tasks.

## UI philosophy

User-facing operational interfaces should prioritize:

- mobile use;
- speed;
- clear hierarchy;
- low visual noise;
- predictable interaction;
- native-feeling controls.

Avoid decorative complexity that does not improve the workflow.