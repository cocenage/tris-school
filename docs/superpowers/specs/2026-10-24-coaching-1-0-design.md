# Coaching 1.0 Design

## Intent
Let a supervisor record a coaching session that actually took place in about a minute, and let the cleaner and authorized managers review it alongside the cleaner's existing control history.

## Current state and evidence
- /checks has a coaching tile with an empty URL.
- No current Coaching model, route, or migration exists in the active application code.
- The old coachings SQLite schema is known from prior read-only inspection and its accessible snapshot contains zero records. Other archives may still contain records; this work will not import or alter them.
- /profile/checks currently shows received and conducted controls only. /profile/all-checks is admin-only, includes an unreachable coaching rendering branch, and materializes all control responses in memory.
- Staff routes are already behind auth and approved. Control detail already checks the cleaner, supervisor, or admin; control creation is limited to admin/supervisor.

## Goals
- Add a small mobile-first Livewire flow to list, create, and view coaching records.
- Store the actual cleaner, authenticated supervisor, conducted date, and free-text description.
- Make /checks coaching entry functional.
- Let supervisors see records they conducted, admins see all with employee/date filtering, and cleaners see only their own records. No editing in MVP.
- Show a single stable, chronologically sorted controls-and-coachings timeline with All / Controls / Coachings filters and database-level pagination.
- Preserve current control authorization, rendering, scores, and history semantics.

## Non-goals
- No scoring, exams, photos, approval, status workflow, Telegram notifications, historical import, production migration, or changes to Classic/Focus controls.
- No claim that the available zero-row snapshot proves historical coaching records never existed.

## Approaches considered
1. Reuse the legacy coachings schema (selected): keep its table and column names, map user_id to cleaner, supervisor_id to author, inspection_date to conducted date, and reason to description in the model/UI. Create the table additively only when absent. This minimizes drift from backups and avoids duplicating data fields.
2. Add modern canonical columns to the same table. This makes names more explicit, but duplicates legacy concepts and requires compatibility rules for every existing copy.
3. Create a parallel coaching table. Rejected because it would fragment any surviving legacy records and create a second source of truth.

## Design calls
- Use the existing coachings table name and legacy columns as the storage contract. Preserve all legacy columns, constraints, and indexes when creating the table from the verified old schema; do not destructively reshape an existing table.
- Route list/create/detail under the existing auth + approved group. Supervisor-only creation uses the currently authenticated user as supervisor_id; cleaner options use the existing active-staff rules scoped to cleaners. Cleaner detail/list queries are always scoped to user_id = auth()->id(); supervisors see their own records; admins may view all.
- Keep /profile/checks received/conducted controls navigation. The cleaner's received-history view combines received controls and own coaching records, with All / Controls / Coachings filters. Keep the conducted-control view unchanged.
- Extend admin staff activity with a per-employee history view that uses the same SQL union timeline query. Apply type/date/person filtering before pagination; use stable date/type/id tie-break ordering and hydrate only the visible page.
- Do not import records from database/old.sqlite or production copies. Any newly found historical rows require a separately reviewed mapping plan.

## Constraints
- Laravel 12, Livewire 4, SQLite-compatible SQL, existing Tailwind and authentication.
- No third-party dependencies, production writes, Telegram sends, or changes to control scoring.
- Test against the isolated test database configured by phpunit.xml.

## Risks and acceptance
- The historical table's old column names may be semantically ambiguous outside the accessible schema snapshot; map only the fields supported by that schema and preserve the remaining fields untouched.
- Equal-day events must remain stable; unauthorized users must not access another cleaner's coaching by changing a URL ID.
- Acceptance requires focused tests for creation/validation/duplicate submit guard, role access, merged pagination/filter ordering, empty states, detail authorization, and unchanged control detail behavior, plus a mobile browser smoke check when available. Production remains unchanged.

## Verified legacy schema (read-only)
The production historical snapshot was queried with SQLite open in READONLY mode. The coachings table has integer autoincrement id; required user_id; nullable supervisor_id, control_id, apartment_id, cleaning_date, inspection_date, previous_cleaner, control_response_ids, reason, sent_at, created_at, updated_at; is_assigned NOT NULL DEFAULT 0; status NOT NULL DEFAULT 'sent'. Foreign keys are user_id→users ON DELETE CASCADE, supervisor_id→users ON DELETE SET NULL, control_id→controls ON DELETE SET NULL, and apartment_id→apartments ON DELETE SET NULL. There are no explicit non-PK indexes. The table contains zero rows in this snapshot. The additive migration must preserve this complete schema and may add query-supporting indexes without renaming or dropping legacy columns. No record data was read.
