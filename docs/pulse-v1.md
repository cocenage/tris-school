# TRIS Pulse — backend foundation

First integration on the TRIS 1.0 native Telegram branch. GET /focus/pulse uses the existing authenticated Laravel web session and returns a private JSON snapshot. No migrations, external calls, AI, push delivery or mutations. UI, new app shell, mascot, planner authoring and cleaning assignments are not part of this commit.

Personal context only: tasks directly assigned to the account (not room membership), authoritative employee calendar, recently reviewed final day-off/vacation decisions and sent cleaner controls. Management aggregates are intentionally absent until district scope is verified. Learning deadlines and cleaning counts are not inferred. Current event window is 24 hours from review/submission, not acknowledgment; acknowledgment and suppression are follow-up work. A decision may overlap the calendar fact; cross-source deduplication is not implemented yet.

Facts have a semantic group, deterministic priority, stable id and optional expiry. Composer uses Russian text only. Snapshot version hashes visible segments and day; generated_at and valid_until are separate. Initial endpoint recomputes on each request, client freshness hint is at most 30 seconds; server cache/invalidation and diagnostics are follow-up tasks. The UI should use text interpolation, not v-html, and treat stale snapshots as stale. Role preview and inactive accounts must not receive personal data.

Existing destinations are transitional links to the old UI, not the final Vue router contract. Do not expose stored control zone to cleaners in the new UI. The old result page still renders red and needs a separate UI fix.

Test: php artisan test --compact --filter=PulseCoreTest ; php artisan test --compact --filter=PulseApiTest. Run in an isolated testing database only.
