# Hosting migration and recovery — issue #136

## Status and scope

Prepared against `c301aaf`. This runbook and the synthetic drill are local deliverables. Hosting, external backups, monitoring, DNS, Telegram ingress, and full-node recovery remain deployment work. The application currently supports separate primary and analytics SQLite databases and an optional dedicated SQLite queue. Confirm the actual deployment inventory privately before applying this plan.

## Proposed initial hosting layout

| Component | Placement | Recovery dependency |
| --- | --- | --- |
| HTTPS proxy, Laravel/PHP, compiled assets | Active Linux host A, release directory per commit, web root `public/` | Same locked dependencies and release artifact |
| Primary SQLite | Host A, persistent private volume outside releases/web root | Consistent recovery bundle |
| Analytics SQLite | Host A, separate physical file and `analytics` connection | Matching bundle generation |
| Optional queue SQLite | Host A, existing configured location | Queue rows, batch/failed history and delivery journal |
| Private documents and public media | Host A, separate persistent `storage/app/private` and `storage/app/public` trees | Matching file manifest and database records |
| Worker, scheduler and Telegram pollers | Host A, existing process/cadence configuration | Exactly one active owner after recovery |
| Encrypted backup repository | Independent host/provider B, separate credentials and retention permissions | Recoverable encryption key held outside A |
| Emergency TRIS static bundle | Independent static host B or C, staff-known HTTPS URL | Last successful allowlisted publication |
| Recovery host | Prepared image or rebuildable host C | Access to B, release artifacts and private configuration escrow |
| External health monitoring | Outside A | Primary `/up`, real read-only app check, Emergency URL and backup freshness |

The starting design uses a single active SQLite writer host. Recovery promotes one replacement after the old host is fenced. Hosting migration preserves the current database engine and disk paths/visibility semantics. PostgreSQL migration and multi-writer service require their own tested change.

Provider selection, region, capacity, recurring cost and document-retention policy await the operator's decisions. Initial sizing requires measured memory, storage growth, request concurrency and backup duration. A second host serving the same local SQLite files over network storage is unsuitable for this layout.

Reuse [Emergency TRIS](../emergency-tris.md) for independent access to approved public instructions. It already carries snapshot timestamps and offline feedback drafts. Private company documents remain authenticated in the recovered application. S3 adapter installation and independent feedback hosting are pending prerequisites if those options are chosen.

## Inventory before migration

Record the following in an access-controlled operations record. Keep values and backup contents outside the public repository.

- Source release SHA, PHP/extensions, Composer lock, Node/build versions, web-server and service definitions.
- Resolved primary, analytics and queue paths. Deduplicate physical paths when queue aliases primary. Include queue batch/failed stores when configured separately.
- Every configured file disk, private/public media roots, symlinks, ownership, permissions, used bytes, and temporary upload/draft retention needs.
- Database migration ledgers for each connection, row counts of critical business and delivery tables, queue ready/delayed/reserved/failed counts.
- Session/cache storage, lock ownership, current ingress mode for each bot, polling offsets, webhook configuration, scheduled commands and worker cadence.
- Encrypted configuration escrow including existing `APP_KEY`, bot credentials and storage credentials. Test escrow access using a recovery operator identity.
- Domain/DNS control, TLS renewal, outbound connectivity, backup destination access, recovery contacts and available maintenance window.

## Recovery bundle and backup policy

Proposed targets for operator review are RPO 24 hours for the initial nightly bundle and RTO 4 hours for manual full-host recovery. They become measured commitments only after the staging drill. Shorter loss windows require more frequent coordinated snapshots and their measured write-pause cost.

1. Suspend all application writes, including web/Livewire/API requests, webhook ingestion, pollers, scheduler, workers and imports. Maintenance middleware alone does not prove webhook/CLI quiescence. Wait for active requests, jobs and transactions to finish. Preserve incoming Telegram updates according to the configured ingress mode and verify provider retention against the planned window.
2. Create a new private generation directory with restrictive permissions and enough free space for databases, media, archive and a verification restore. Record release SHA and UTC snapshot boundary. Never reuse an earlier directory.
3. Snapshot each distinct SQLite file using the SQLite backup API or CLI `.backup`. The API includes committed WAL content. Keep writers stopped across all database snapshots and the file copy to obtain a matching application recovery set. Ordinary copying of an active SQLite main file can miss journal data. The existing [queue isolation runbook](sqlite-queue-isolation.md) permits its raw copy only after all writers stop and no journal remains.
4. Copy the private/public file stores and any additional inventoried persistent disks. Preserve visibility, ownership metadata and symlinks deliberately. Include escrow reference and configuration version without placing plaintext credentials in the manifest.
5. Run `PRAGMA integrity_check` and `PRAGMA foreign_key_check` against every copied database. Compare critical row counts, migration ledgers, queue state and a sample of DB-to-file references. Produce a SHA-256 manifest for every bundle file and authenticate the manifest through the encrypted backup system.
6. Resume the original processes after local capture succeeds. Encrypt and transfer the completed bundle to the independent repository. Mark a generation successful only after upload and remote verification complete. An incomplete upload remains unavailable for recovery selection.
7. Retain the last verified recovery generation during any failed backup. Suggested retention is 7 daily, 4 weekly and 6 monthly generations, subject to the document/privacy retention decision. Protect retained generations from deletion by the normal application identity.
8. Alert on a missed backup, failed integrity check, insufficient space, expired repository credentials, failed upload, or freshness beyond the agreed RPO. Restore to a fresh staging directory monthly and after backup-tool changes. Record measured duration and observed data loss boundary.

The repository contains a reproducible synthetic drill below. Selecting and scheduling the encrypted off-host backup tool is an outstanding infrastructure step. No production backup schedule is installed by this change.

## Staged migration

### Stage 1 — prepare and rehearse

Build the same application release on a private candidate host using locked dependencies and compiled assets. Keep background processes and external transports disabled. Install runtime extensions, writable persistent directories and TLS. Validate permissions and private file access through application authorization.

Use a synthetic or explicitly approved sanitized recovery bundle. Exercise login under test identities, role restrictions, profile/calendar, applications, controls and draft media, apartment documents, and notification links. Test queue processing with fake transports and isolated jobs. Confirm the existing scheduled command definitions and cadence without starting production tasks. Validate separate analytics and queue connections.

Rehearse recovery and rollback on staging. Verify restored `APP_KEY` compatibility with encrypted fixtures and authentication flows. Check `/up` plus a database-backed authenticated read and protected download. `/up` alone cannot prove database or document availability.

### Stage 2 — final cutover

1. Record an operator and rollback decision point. Lower DNS TTL sufficiently in advance for the previous TTL to expire. Record previous records and TLS configuration.
2. Freeze the source with the full writer procedure above. Disable automatic service restarts on the retired host. Capture and verify a final bundle including delivery journals, polling offsets and media.
3. Restore into fresh candidate directories. Keep all writers and outgoing transports disabled. Use the same application release and schema for the hosting-only cutover. Run integrity, row/file reconciliation and read-only application checks.
4. Switch ingress to the candidate. Fence old-origin writes even while DNS caches retain the old address. Validate the unchanged public hostname, TLS, Telegram Mini App/authentication callbacks and webhook reachability. Record any necessary Telegram configuration operation for the operator.
5. Enable exactly one ingress consumer per bot and one owner for workers/scheduler according to the existing topology. Preserve queue reservations, attempts, delays and outbound delivery journals. Inspect uncertain deliveries individually before authorizing any replay.
6. Confirm the first normal scheduled and worker cycles, representative staff reads/writes, private download permissions, queue age, error logs and external health. Publish and open Emergency TRIS on its independent host. Retain the fenced old host and pre-cutover generation for the agreed rollback window.

## Rollback

### Before the candidate accepts writes

Stop/fence the candidate, restore the previous ingress/DNS records, and resume the original host's unchanged processes. Confirm only one active owner and reconcile any ingress updates already received by the candidate before resuming. Preserve candidate logs and bundle for investigation.

### After the candidate accepts writes

Freeze both hosts and capture the candidate's current databases, queue/delivery state and media as a new consistent generation. Restore this generation into fresh private directories on the original host using the same release. Validate it before switching ingress and enabling a single process owner. Keep both generations and both hosts' evidence.

Returning to the pre-cutover snapshot loses accepted writes. If current candidate data cannot be recovered, record the exact recovery boundary and require the operator to choose the acceptable loss before reopening writes. For a schema-changing release, a tested forward-compatible rollback or separate restore procedure is required. Hosting-only cutover deliberately avoids that dependency.

## Full-node failure and staging acceptance

| Injected failure | Expected behavior | Evidence to record |
| --- | --- | --- |
| Active app host stopped or network-isolated | Primary requests fail or show maintenance, independent Emergency instructions remain readable | External checks, staff-browser screenshot, snapshot age |
| Database unavailable | Database-backed readiness fails and writes remain blocked | Read/write probes, alert timestamp, app error handling |
| Backup repository unavailable | Backup fails visibly, last verified generation retained | Freshness alert and existing generation check |
| Emergency feedback receiver unavailable | Draft retained and copyable report available | Browser reload and explicit unsent status |
| Corrupt latest recovery generation | Verification rejects it, earlier verified generation selected | Checksums, integrity result and selected boundary |
| Old host returns during recovery | Fencing prevents a second writer, worker or poller | Service/ingress ownership and duplicate-delivery checks |

On staging, stop host A, observe independent Emergency availability, fence A, restore to C, reconcile database and media state, and switch staging ingress. Then test A returning. Measure detection, restore and total recovery time separately. Verify Telegram ingress and background work using test accounts and fake transports before authorizing any real integration check.

The local source-directory loss drill proves a restore can proceed without its source fixtures. Actual node isolation, DNS/TLS behavior, independent static hosting, private-document authorization after recovery, Telegram ingress and duplicate-consumer prevention require the staging exercise above.

## Local verification evidence

Run from the repository root with Python 3 and its standard SQLite module.

```sh
python3 tools/operations/sqlite-recovery-drill.py
```

The script creates generated fixtures under its own temporary directory, uses no Laravel bootstrap or environment configuration, and cleans up only its generated files. It takes backup-API snapshots of primary/analytics/queue fixtures in both DELETE and WAL modes, copies private/public document fixtures, verifies checksums and database rows, makes the source path unavailable, restores, and rejects a deliberately corrupted backup copy. A later source commit is absent after restore, explicitly testing the snapshot loss boundary.

Local run on 2026-11-15 passed both modes. Each restored 3 databases and 2 document fixtures. Missing source and corrupt backup checks passed. Integrity, foreign-key checks, row equality and file hashes passed. Fixture restore times were 0.001682 seconds (DELETE) and 0.001127 seconds (WAL). Those tiny fixture timings provide reproducibility evidence only. Production RPO/RTO, cross-provider failure recovery and business-data restoration remain unmeasured.

## Remaining acceptance decisions

- Operator selects hosting/storage providers, region, budget, retention and recovery owner.
- Operator approves document classification, private recovery access and encryption-key custody.
- Infrastructure is provisioned and real backup scheduling/monitoring is installed.
- Staging validates the full-node failure and rollback matrix with representative data volumes.
- Production cutover is scheduled and executed after those checks pass.
