# Current Project State

Last reviewed: 2026-08-29

## Git

Development branch:

`release/tris-v1`

Recent known repository state:

- tracked working tree was clean at the latest project audit;
- recent development focused on Telegram callbacks, mobility filtering, rich Telegram request cards and apartment Telegram import.

Do not assume this state is still current without checking `git status` and recent log when making changes.

## Current priority

Current product work is focused on improving the quality-control interface.

Goals include:

- reliable section navigation;
- predictable anchors;
- minimal mobile-first UX;
- better section/question readability;
- fast people/apartment selection;
- compact progress/status;
- reliable validation navigation.

The Control business logic itself should remain unchanged unless explicitly requested.

## Control UI

A recent UI refactor introduced/attempted:

- compact room navigation;
- searchable people selection;
- searchable apartment selection;
- photos/avatars in selectors;
- completed-room states;
- compact progress;
- control menu;
- reset action;
- improved scrolling/validation behavior.

A Blade syntax error was encountered during this work:

`unexpected token "endforeach", expecting end of file`

Treat the real current file as source of truth before continuing.

Do not assume the unfinished refactor is valid until Blade compilation succeeds.

## Telegram

Callback handling has previously been repaired and hardened.

Known architectural requirements:

- preserve role checks;
- callback state changes should survive Telegram notification/edit failures;
- avoid duplicate sends;
- do not interpret transport success solely from application-side assumptions.

Production webhook/network state is environment-specific and must not be inferred from repository tests alone.

## Apartment Telegram import

Telegram JSON import exists and has been tested.

Known product limitation:

raw Telegram history is not yet an ideal knowledge-base UX.

Desired future direction is semantic/editable content blocks rather than exposing a raw chronological chat dump.

Media cannot be recovered from Telegram exports where Telegram itself exported placeholders instead of files.

## Mobility

Known historical problem:

production-like data could still show stale or low-value transport alerts even when synthetic tests passed.

Future fixes must use:

reproduce exact real-data scenario
→ inspect exact rows
→ find exact filter failure
→ fix
→ rerun same scenario
→ regression test.

Do not accept green synthetic tests as proof of correct digest behavior.

## Documentation

README is still largely Laravel boilerplate.

Apartment documentation is partially outdated relative to the implemented Telegram import.

`.env.example` may not contain all current application configuration names.

These are documentation/developer-experience issues, not current product blockers.

## Deployment

Normal development happens locally.

Production deployment is a separate explicit action.

Do not:

- deploy;
- migrate production;
- restart production workers;
- change production environment;

unless explicitly requested.