# Known Issues and Risks

This file contains unresolved or environment-sensitive issues.

## Control UI

Recent large Blade/Volt UI changes caused a Blade parser error around a nested `@endforeach`.

Before continuing visual work:

1. make the current template compile;
2. run targeted control checks;
3. only then continue UX work.

Large structural Blade changes should be compiled incrementally.

## Control browser testing

Do not assume browser behavior from Blade/tests alone.

Anchor alignment, sticky navigation, mobile overlays and Livewire rerenders require real browser verification when available.

## Mobility real-data correctness

Mobility filtering has previously passed automated tests while still producing bad production-like output.

Known classes of bad output include:

- stale line closures;
- generic low-information transport entries;
- duplicate/overlapping line events;
- old event-specific notices;
- transport sections appearing when nothing important is currently active.

Always reproduce using the actual failing rows/date when available.

## Telegram ingress

A working Laravel webhook controller does not prove Telegram can reach production.

If callbacks do not reach Laravel logs, investigate the ingress/hosting/network boundary instead of repeatedly rewriting callback business logic.

## Telegram duplicate delivery

Telegram API requests may succeed remotely while the HTTP client times out locally.

Fallback behavior after uncertain network errors can produce duplicate notifications.

Treat retry/fallback changes carefully.

## Apartment import UX

The technical Telegram import works, but raw chronological chat import is not yet an ideal final knowledge-base representation.

This is a product UX limitation, not necessarily a parser failure.

## External mobility sources

MIT, ATM, Trenord and public Telegram pages are external dependencies.

Their availability/HTML structure may change.

Source errors should not crash unrelated application functionality.

## Environment

Actual production values for:

- database;
- queue worker;
- scheduler;
- Telegram webhook;
- external credentials;

must be verified from the environment when relevant.

Do not infer them solely from config defaults or repository files.