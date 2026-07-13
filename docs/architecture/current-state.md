# Tris Service Academy — Current Architecture

Snapshot date: 2026-07-12

## Product role

The current application is broader than a classic learning management system. It is an internal operations portal for Tris Service employees with knowledge, quality control, staff requests, tasks, calendars, Telegram integrations, and administrative tools.

## Runtime stack

- Laravel 12.56
- PHP 8.4
- Livewire 4.2 with single-file Volt components
- Filament 5.4
- Tailwind CSS 4
- Alpine.js 3
- SQLite as the primary application database
- Separate SQLite `analytics` connection for Telegram archive data
- Database cache, queue, and sessions
- Spatie Activity Log

## Application size

- 38 Eloquent models
- 53 migrations
- 131 registered routes
- 141 Filament PHP files
- 32 Livewire Volt page/components
- 21 services
- 8 scheduled/console commands
- 1 queued job
- 2 placeholder-level tests before stabilization patch 0.1

## Current modules

### Identity and access

Core entities:

- `User`
- `UserPanelAccess`
- `UserCalendarTypeAccess`
- `UserNotificationSetting`

Authentication is based primarily on Telegram Mini App / Telegram Login Widget data. New users are created with `pending` status and require approval.

Filament access is controlled through `User::canAccessPanel()` and per-panel access records.

### Knowledge base

Core entities:

- `InstructionCategory`
- `Instruction`
- `Tag`

Instructions are flat knowledge articles stored as a JSON block collection. Supported presentation blocks include text, hero sections, steps, checklists, tips, FAQ, images, and links.

There are currently no course, lesson, test, attempt, enrollment, progress, or certificate entities.

### Quality control

Core entities:

- `Apartment`
- `Control`
- `ControlResponseDraft`
- `ControlResponse`

The control schema and submitted answers are stored as JSON snapshots. Completed controls contain scoring, penalties, critical-failure state, result zones, and reward-program links.

### Staff requests

Core entities:

- `DayOffRequest` / `DayOffRequestDay`
- `VacationRequest` / `VacationRequestDay`
- `InventoryRequest` / `InventoryRequestLine`
- `SalaryQuestion`
- `ScheduleQuestion`
- `FeedbackSuggestion`

Each request type currently has its own model, Livewire page, Telegram formatting service, and admin resource.

### Tasks and collaboration

Core entities:

- `TaskRoom`
- `TaskBoard`
- `TaskBoardColumn`
- `Task`
- `TaskComment`
- `TaskChecklistItem`
- `TaskNotification`

The task system supports rooms, boards, columns, assignees, comments, checklists, due dates, Telegram notifications, and calendar projection.

### Calendar and mobility

Core entities:

- `CalendarEvent`
- `MobilityAlert`

Scheduled jobs collect mobility alerts, send digests, notify about tomorrow's calendar, check task deadlines, and synchronize Tris Mare snapshots.

### Reward and external snapshots

Core entities:

- `RewardProgram`
- `RewardProgramPointEvent`
- `TrisMareSnapshot`

Quality-control responses can create polymorphic reward point events. Tris Mare data is synchronized separately from an external spreadsheet source.

### Telegram

Core entities on the `analytics` database connection:

- `TelegramChat`
- `TelegramTopic`
- `TelegramUser`
- `TelegramMessage`
- `TelegramAttachment`

Telegram currently serves four different roles:

1. User authentication.
2. Employee notifications.
3. Approval callbacks for access and days off.
4. Message archive and instruction auto-replies.

Two separate webhook controllers currently implement overlapping Telegram message persistence logic.

## Filament panels

### `admin`

The main panel discovers every resource in `app/Filament/Resources` and contains the real administration surface.

### `education`

The provider points to `app/Filament/Education`, but this directory does not exist in the snapshot. The panel currently mounts shared user, day-off, and vacation resources manually. It is not yet a real education module.

### `finance`

The provider points to `app/Filament/Finance`, but no finance resources or pages are present in the snapshot. The panel currently contains only the default dashboard.

## Frontend structure

Most employee-facing pages are single-file Livewire/Volt components. Several have grown into very large mixed UI/domain files:

- task calendar: about 2,364 lines
- control form: about 1,926 lines
- weekend form: about 1,217 lines
- task list: about 906 lines
- vacation form: about 822 lines

These files combine state, validation, database mutations, authorization, formatting, and large Blade/CSS/JavaScript sections.

## Operations

Scheduled commands:

- `calendar:notify-tomorrow` daily at 06:00
- `tasks:check-deadlines` every 15 minutes
- `mobility:sync` daily at 07:30
- `mobility:digest` daily at 08:00
- `tris-mare:sync` daily at 20:15 Europe/Rome

The project uses a database queue, but this snapshot does not include production worker, supervisor, deployment, backup, or monitoring configuration.

## Known architectural risks

### Critical

1. Telegram approval callbacks did not consistently authorize the person pressing the button. Chat membership alone was treated as sufficient in parts of the flow.
2. Full Telegram updates and outbound message payloads could be written to application logs, including employee message content and personal data.
3. `app/View/Components/ui/bottom-sheet.php` contained an invalid PHP class name (`bottom-sheet`) and failed static PHP parsing.

### High

1. Telegram message ingestion is duplicated between two webhook implementations and stores `raw` data inconsistently.
2. Telegram archive models use the `analytics` connection, while the visible migrations are tracked on the primary database. The real analytics schema lifecycle must be verified before schema changes.
3. The automated test suite is effectively absent. The only feature test expected `/` to return 200 although the intended guest behavior is a redirect.
4. SQLite is simultaneously used for application writes, queue, cache, and sessions. Larger traffic, Telegram bursts, and AI jobs will increase lock risk.
5. Business rules are distributed between models, large Volt components, Filament pages, controllers, and Telegram services.

### Medium

1. `UserNotificationSetting` and its migration are empty scaffolds.
2. The education and finance Filament panels are placeholders rather than isolated modules.
3. PWA manifest, service worker, and application/content version update flow are not present in this snapshot.
4. Status and role values are repeated as strings instead of centralized enums/value objects.
5. There are no policies for most domain entities; authorization is implemented ad hoc in components and models.

## Stabilization patch 0.1

The first patch intentionally avoids product redesign. It:

- verifies Telegram webhook secrets using `hash_equals`;
- requires an approved active admin for access approval callbacks;
- requires an approved active admin or supervisor for day-off callbacks;
- requires callback messages to originate from an explicitly allowed chat;
- removes full Telegram update and message-body logging;
- stores Telegram `raw` payload through the model's array cast correctly;
- updates Telegram user name and last-seen data consistently;
- adds missing user timestamp fillable/casts;
- removes the invalid `bottom-sheet` PHP class while retaining the anonymous Blade component;
- replaces the incorrect placeholder feature test with real guest/login smoke tests.

## Next architectural move

Before adding AI or a full LMS, introduce a stable application layer:

1. Extract Telegram ingestion and authorization into shared services.
2. Add domain actions for staff requests, controls, and tasks.
3. Add policies and integration tests around all mutating operations.
4. Add an automatically generated project manifest.
5. Only then add an AI read layer and MCP tools.

## AI boundary

The future AI layer must start read-only. It should receive project facts through explicit resources and tools rather than direct database or shell access.

Initial read tools:

- project overview
- route map
- model relation map
- module manifest
- recent application errors
- queue health
- scheduler health
- Telegram ingestion health
- instruction search
- anonymized control statistics

All write operations must be narrow, audited, and require approval.
