# CLI Contracts

## Bounded Replay

```text
telegram:operational-replay
    {--date= : One past calendar date in the application timezone}
    {--from= : Inclusive start date; requires --to}
    {--to= : Inclusive end date; requires --from}
    {--json : Emit machine-readable result}
```

Rules:

- Exactly one selector is required: `--date`, or `--from` together with `--to`.
- Boundaries must be valid past dates, `from <= to`, and span no more than seven consecutive calendar dates.
- Validation completes before any message is examined or ledger row is written.
- Eligible messages use the existing work-chat types and configured allowed-chat restriction.
- Processing order is `sent_at ASC, id ASC`, in bounded chunks.
- The command invokes the same observer contract as live processing; it does not dispatch live observer jobs and never invokes Telegram transport.
- For each timestamp, all messages at that timestamp are observed before unanswered deadlines due at that timestamp mature. At range end, only deadlines at or before the inclusive end boundary mature.
- Exit code is non-zero for invalid boundaries or unrecoverable setup failure. Per-message failures are counted and reported; successful messages remain committed and retryable replay is safe.

Human output includes boundaries, examined/no-event/created/updated/evidence/resolved/reopened/dismissed/pending/failed counts, affected event keys, and an explicit `Telegram actions: 0` line.

JSON output follows the `ReplayResult` shape in `data-model.md` and contains no raw payload by default.

## Read-only Ledger Inspection

```text
telegram:operational-events
    {--event= : Exact stable event key}
    {--message= : Local Telegram message id, including no-event/failure decisions}
    {--date= : Events or observations touching one calendar date}
    {--status= : Filter by current event status}
    {--json : Emit machine-readable result}
```

Rules:

- Filters may be combined; unfiltered output is bounded to a documented recent default rather than the entire ledger.
- Event detail includes current summary/type/status/confidence/uncertainty, ordered lifecycle entries, and source message identifiers (chat, topic, external message ID, sent time).
- Message detail includes revision decisions, reason codes, failures, and linked event keys.
- Default human and JSON output omit raw Telegram payloads and full message bodies.
- The command performs no writes and no external calls.
