# Artisan Command Contracts

## Evening preview

`telegram:evening-intelligence-preview --date=YYYY-MM-DD [--district=KEY] [--json]`

- Date remains required.
- Without district, preserves the existing all-ledger preview.
- With district, requires a complete configured route and filters by its forum.
- Human mode contains no internal identifiers or lifecycle/debug fields.
- JSON mode contains the complete technical projection and performs no writes.

## Evening send

`telegram:evening-intelligence-send [--date=YYYY-MM-DD] [--district=KEY] [--dry-run] [--json]`

- Date defaults to the current application date for scheduler use.
- District optionally limits processing; otherwise all complete routes are considered.
- Dry-run never calls Telegram and reports destination labels plus output/status.
- Real send fails safely while the evening-delivery flag is off.
- Empty district summaries are `skipped_empty` and never sent.
- A non-empty enabled route calls the existing sender once with its chat and duty thread.

## Morning digest

`mobility:digest [--date=YYYY-MM-DD] [--district=KEY] [--dry-run]`

- When complete district routes exist, produces one independently filtered digest per selected/all district routes.
- Each regional digest uses configured coordinates and its matching duty destination.
- District-specific mobility is restricted to exact configured matches; unknown/city-wide items are shared.
- When no district routes exist, preserves legacy target behavior.
- Dry-run never calls Telegram.

## Scheduled commands

- Existing `mobility:digest` schedule remains.
- Evening schedule invokes `telegram:evening-intelligence-send`; default flag makes it externally inert until explicitly enabled.
