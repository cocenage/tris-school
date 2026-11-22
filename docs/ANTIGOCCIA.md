# ANTIGOCCIA — TRIS Academy

Implementation package, reviewed against `cocenage/tris-school` main at commit
`338cf95d940a8d7a1e807029200b4afbe3b3f38e` (22 November 2026).

## What is included

- `/antigoccia` website page (Livewire/Blade, consistent with Academy's mobile styling),
  manual apartment selection and backdated cleaning date; author is authenticated staff.
- Four canonical surfaces, with two 0/1/2 choices for each. Dirt 0 forces
  "not required"; failed removal requires an image for that surface.
- Final "remaining contamination" yes/no, with mandatory proof image; an already
  required surface photo can count as proof without uploading the same image twice.
- `/antigoccia` Telegram command handled before private auto-reply and group
  allowlist branch. It responds where the work bot receives the command, including
  Telegram forum topics. Button opens authenticated Academy page; it does not
  grant access by itself. Mentioned commands require the configured bot username.
- Private photo storage, gated by the existing `ApartmentAccessService`.
- Apartment history with date of cleaning, actual creation timestamp, cleaner
  snapshot, responses and photos. Pagination; never overwrites prior reports.
- Coating application dates, entered only by Academy apartment managers;
  per-surface observations and earliest difficulty after the latest application.
- UUID deduplication for repeated form submissions / callback retry.

## Important semantics

The current Academy's public apartment/schedule model does not provide a
verified row for every completed cleaning per apartment. This module therefore
**does not** falsely claim that the number of questionnaires equals the number
of cleanings. Its effectiveness dashboard counts *submitted questionnaires*
after the last recorded coating application. A future verified cleaning ledger
can add actual cleaning-number analysis without changing historical reports.

A submitted backdated report is assigned to the calendar date explicitly
chosen by the cleaner and retains the actual database creation timestamp.
Staff can see only apartments authorized by the current grant-based Academy
service. Supervisors without a grant are not given automatic access.

## Applying the patch (local developer checkout, NOT production)

```sh
python /path/to/antigoccia_patch/install.py /path/to/tris-school
python /path/to/antigoccia_patch/install.py /path/to/tris-school --apply
cd /path/to/tris-school
php artisan migrate --pretend
php artisan route:list --path=antigoccia
php artisan test --filter=Antigoccia
npm run build
```

Then review `git diff`, verify authorized/unauthorized access, image uploads,
photo read protection, group/topic/private command behavior, and UI in actual
Telegram mobile before opening PR. Do not deploy without testing on a staging DB.

## Telegram configuration

The command uses the existing `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_BOT_USERNAME`, and work webhook, **not a new Telegram bot**.
Register `antigoccia` (lowercase, description "Антикапля — анкета") via
BotFather if you want it in Telegram's command menu. The command's URL button
can open the Academy website within Telegram's browser; staff must authenticate
there as usual.

## Privacy, performance and operational limits

Photos are max 10MB each (JPG/PNG/WebP) and stored on Laravel's `local`
filesystem. If a deployment uses multi-instance/multi-server infrastructure,
move private objects to a shared private disk and update the media controller;
local per-server files won't be available from other hosts. Local disk and
Livewire temp uploads must have capacity and matching PHP/web-server size
limits. Existing focus upload issues should be independently tested.

No change to Focus Control, login, other bot commands or existing records.

**Not yet done:** Merge/deploy to production; automatic matching against a
verified per-apartment cleaning schedule; integration tests on the actual
production-equivalent stack. These need repository write access and staging
validation.
